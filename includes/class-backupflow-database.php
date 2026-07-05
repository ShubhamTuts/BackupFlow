<?php
/**
 * Database export, import, and serialized-safe URL rewriting.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Database {
	public function get_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BackupFlow must enumerate site tables for export and migration.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return array_values( array_filter( array_map( 'sanitize_text_field', (array) $tables ) ) );
	}

	public function export_to_file( $file_path, $tables = null, $progress_callback = null ) {
		global $wpdb;

		$tables = is_array( $tables ) && $tables ? $tables : $this->get_tables();
		$dir    = dirname( $file_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'Could not create database export folder.', 'backupflow' ) );
		}

		$header = "-- BackupFlow database export\n-- Site: " . home_url() . "\n-- Created: " . gmdate( 'c' ) . "\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n";
		file_put_contents( $file_path, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$total_tables = max( 1, count( $tables ) );
		$table_index  = 0;

		foreach ( $tables as $table ) {
			$table_index++;
			$table_sql = $this->quote_identifier( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Backup export requires the table creation statement.
			$create    = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			if ( ! $create || empty( $create[1] ) ) {
				continue;
			}

			$chunk  = "\n-- --------------------------------------------------------\n";
			$chunk .= "-- Table structure for {$table}\n";
			$chunk .= 'DROP TABLE IF EXISTS ' . $table_sql . ";\n";
			$chunk .= $create[1] . ";\n\n";
			file_put_contents( $file_path, $chunk, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export must read table columns.
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
			if ( ! $columns ) {
				continue;
			}

			$column_sql = '(' . implode( ', ', array_map( array( $this, 'quote_identifier' ), $columns ) ) . ')';
			$offset     = 0;
			$limit      = 250;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export reads table rows in chunks.
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, $limit ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}

				$insert_lines = array();
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $columns as $column ) {
						$values[] = $this->sql_value( array_key_exists( $column, $row ) ? $row[ $column ] : null );
					}
					$insert_lines[] = '(' . implode( ', ', $values ) . ')';
				}

				if ( $insert_lines ) {
					file_put_contents( $file_path, 'INSERT INTO ' . $table_sql . ' ' . $column_sql . " VALUES\n" . implode( ",\n", $insert_lines ) . ";\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}

				$offset += $limit;
			} while ( count( $rows ) === $limit );

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $table_index, $total_tables, $table );
			}
		}

		file_put_contents( $file_path, "\nSET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $file_path;
	}

	public function prepare_export_state( $tmp_dir, $tables = null ) {
		$tables    = is_array( $tables ) && $tables ? $tables : $this->get_tables();
		$parts_dir = trailingslashit( $tmp_dir ) . 'database';
		if ( ! wp_mkdir_p( $parts_dir ) ) {
			throw new RuntimeException( esc_html__( 'Could not create database parts folder.', 'backupflow' ) );
		}

		return array(
			'tables'        => array_values( $tables ),
			'table_index'   => 0,
			'offset'        => 0,
			'last_pk'       => null,
			'primary_key'   => '',
			'part_index'    => 0,
			'parts'         => array(),
			'parts_dir'     => $parts_dir,
			'table_started' => false,
			'rows_exported' => 0,
			'done'          => false,
		);
	}

	public function export_chunk( $state, $row_limit = 250, $time_limit = 8, $progress_callback = null ) {
		global $wpdb;

		$state      = is_array( $state ) ? $state : array();
		$started_at = microtime( true );
		$row_limit  = max( 25, (int) $row_limit );
		$time_limit = max( 2, (int) $time_limit );

		if ( ! empty( $state['done'] ) ) {
			return $state;
		}

		$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? $state['tables'] : $this->get_tables();
		$total  = count( $tables );

		while ( (int) $state['table_index'] < $total ) {
			$table = $tables[ (int) $state['table_index'] ];

			if ( empty( $state['table_started'] ) ) {
				$this->append_sql_part( $state, "-- --------------------------------------------------------\n-- Table structure for {$table}\n" );
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				if ( ! $create || empty( $create[1] ) ) {
					$state['table_index']++;
					$state['offset']        = 0;
					$state['last_pk']       = null;
					$state['primary_key']   = '';
					$state['table_started'] = false;
					continue;
				}

				$state['primary_key']   = $this->numeric_primary_key( $table );
				$state['table_started'] = true;
				$this->append_sql_part( $state, 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) . ";\n" . $create[1] . ";\n\n" );
			}

			$rows = $this->export_rows( $table, $state, $row_limit );
			if ( ! $rows ) {
				if ( is_callable( $progress_callback ) ) {
					call_user_func( $progress_callback, (int) $state['table_index'] + 1, $total, $table );
				}
				$state['table_index']++;
				$state['offset']        = 0;
				$state['last_pk']       = null;
				$state['primary_key']   = '';
				$state['table_started'] = false;
				continue;
			}

			$columns = array_keys( reset( $rows ) );
			$this->append_sql_part( $state, $this->insert_sql( $table, $columns, $rows ) );
			$state['rows_exported'] += count( $rows );

			if ( ! empty( $state['primary_key'] ) ) {
				$last = end( $rows );
				$state['last_pk'] = isset( $last[ $state['primary_key'] ] ) ? $last[ $state['primary_key'] ] : $state['last_pk'];
			} else {
				$state['offset'] += count( $rows );
			}

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, (int) $state['table_index'] + 1, $total, $table );
			}

			if ( microtime( true ) - $started_at >= $time_limit ) {
				return $state;
			}
		}

		$this->append_sql_part( $state, "\nSET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n" );
		$state['done'] = true;
		return $state;
	}

	public function import_file( $file_path, $progress_callback = null ) {
		$state = array(
			'file_path'       => $file_path,
			'offset'          => 0,
			'statement'       => '',
			'statements_done' => 0,
			'done'            => false,
		);

		while ( empty( $state['done'] ) ) {
			$state = $this->import_file_chunk( $state, 100, 20, $progress_callback );
		}

		return (int) $state['statements_done'];
	}

	public function import_file_chunk( $state, $max_statements = 100, $time_limit = 8, $progress_callback = null ) {
		global $wpdb;

		$state = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'file_path'       => '',
				'offset'          => 0,
				'statement'       => '',
				'statements_done' => 0,
				'source_prefix'    => '',
				'destination_prefix' => '',
				'foreign_key_fallbacks' => 0,
				'done'            => false,
			)
		);

		if ( ! empty( $state['done'] ) ) {
			return $state;
		}

		$file_path = $state['file_path'];
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new RuntimeException( esc_html__( 'Database SQL file is missing or unreadable.', 'backupflow' ) );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not open the SQL file.', 'backupflow' ) );
		}

		fseek( $handle, (int) $state['offset'] );
		$started_at = microtime( true );
		$processed  = 0;
		$statement  = (string) $state['statement'];

		$this->prepare_import_session();

		try {
			while ( ! feof( $handle ) ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					continue;
				}

				$trimmed = trim( $line );
				if ( '' === $statement && ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) ) {
					$state['offset'] = ftell( $handle );
					continue;
				}

				$statement .= $line;
				$state['offset'] = ftell( $handle );

				if ( ';' !== substr( rtrim( $line ), -1 ) ) {
					continue;
				}

				$query = trim( $statement );
				if ( $query ) {
					$query  = $this->rewrite_statement_prefix( $query, $state );
					$result = $this->query_import_statement( $query );

					if ( ! empty( $result['foreign_key_fallback'] ) ) {
						$state['foreign_key_fallbacks']++;
					}

					$processed++;
					$state['statements_done']++;
					if ( is_callable( $progress_callback ) && 0 === $state['statements_done'] % 25 ) {
						call_user_func( $progress_callback, $state['statements_done'] );
					}
				}

				$statement = '';
				if ( $processed >= $max_statements || microtime( true ) - $started_at >= $time_limit ) {
					break;
				}
			}

			$state['statement'] = $statement;
			if ( feof( $handle ) && '' === trim( $statement ) ) {
				$state['done'] = true;
			}
		} finally {
			$this->finish_import_session();
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $state;
	}

	public function search_replace_url( $from, $to, $progress_callback = null ) {
		$state = $this->prepare_rewrite_state();
		$pairs = $this->url_pairs( $from, $to );
		while ( empty( $state['done'] ) ) {
			$state = $this->search_replace_url_chunk( $state, $pairs, 200, 20, $progress_callback );
		}

		update_option( 'home', untrailingslashit( (string) $to ) );
		update_option( 'siteurl', untrailingslashit( (string) $to ) );

		return (int) $state['changed'];
	}

	public function prepare_rewrite_state() {
		return array(
			'tables'      => $this->get_tables(),
			'table_index' => 0,
			'offset'      => 0,
			'changed'     => 0,
			'done'        => false,
		);
	}

	public function search_replace_url_chunk( $state, $pairs, $limit = 200, $time_limit = 8, $progress_callback = null ) {
		global $wpdb;

		$state = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'tables'      => $this->get_tables(),
				'table_index' => 0,
				'offset'      => 0,
				'changed'     => 0,
				'done'        => false,
			)
		);
		$pairs = is_array( $pairs ) ? $pairs : array();
		if ( ! $pairs ) {
			$state['done'] = true;
			return $state;
		}

		$started_at = microtime( true );
		$tables     = array_values( (array) $state['tables'] );

		while ( (int) $state['table_index'] < count( $tables ) ) {
			$table     = $tables[ (int) $state['table_index'] ];
			$table_sql = $this->quote_identifier( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration rewrite must inspect table columns.
			$columns   = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			$text_cols = array();
			$keys      = array();

			foreach ( (array) $columns as $column ) {
				$field = $column['Field'];
				$type  = strtolower( $column['Type'] );
				if ( 'PRI' === $column['Key'] ) {
					$keys[] = $field;
				}
				if ( preg_match( '/char|text|blob|json|enum|set/i', $type ) ) {
					$text_cols[] = $field;
				}
			}

			if ( ! $text_cols || ! $keys ) {
				$state['table_index']++;
				$state['offset'] = 0;
				continue;
			}

			$select_cols = array_unique( array_merge( $keys, $text_cols ) );
			$select_sql  = implode( ', ', array_map( array( $this, 'quote_identifier' ), $select_cols ) );

			$rows           = $wpdb->get_results( $wpdb->prepare( "SELECT {$select_sql} FROM %i LIMIT %d, %d", $table, (int) $state['offset'], (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$processed_rows = 0;
			foreach ( (array) $rows as $row ) {
				$processed_rows++;
				$updates = array();
				foreach ( $text_cols as $column ) {
					if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
						continue;
					}

					$new = $this->replace_value_pairs( $row[ $column ], $pairs );
					if ( $new !== $row[ $column ] ) {
						$updates[ $column ] = $new;
					}
				}

				if ( $updates ) {
					$where = array();
					foreach ( $keys as $key ) {
						$where[ $key ] = $row[ $key ];
					}
					$wpdb->update( $table, $updates, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$state['changed']++;
				}

				if ( microtime( true ) - $started_at >= $time_limit ) {
					$state['offset'] += $processed_rows;
					return $state;
				}
			}

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $table, (int) $state['changed'] );
			}

			if ( count( (array) $rows ) < $limit ) {
				$state['table_index']++;
				$state['offset'] = 0;
			} else {
				$state['offset'] += $limit;
			}
		}

		$state['done'] = true;
		return $state;
	}

	public function url_pairs( $from, $to ) {
		$from = untrailingslashit( (string) $from );
		$to   = untrailingslashit( (string) $to );
		if ( '' === $from || '' === $to || $from === $to ) {
			return array();
		}

		$pairs = array(
			array( $from, $to ),
			array( trailingslashit( $from ), trailingslashit( $to ) ),
		);

		if ( 0 === strpos( $from, 'http://' ) ) {
			$pairs[] = array( preg_replace( '#^http://#', 'https://', $from ), preg_replace( '#^http://#', 'https://', $to ) );
		} elseif ( 0 === strpos( $from, 'https://' ) ) {
			$pairs[] = array( preg_replace( '#^https://#', 'http://', $from ), preg_replace( '#^https://#', 'http://', $to ) );
		}

		$unique = array();
		foreach ( $pairs as $pair ) {
			if ( $pair[0] && $pair[1] && $pair[0] !== $pair[1] ) {
				$unique[ $pair[0] ] = $pair;
			}
		}

		return array_values( $unique );
	}

	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	private function numeric_primary_key( $table ) {
		global $wpdb;

		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$primary = array();
		foreach ( (array) $columns as $column ) {
			if ( 'PRI' === $column['Key'] ) {
				$primary[] = $column;
			}
		}

		if ( 1 !== count( $primary ) ) {
			return '';
		}

		return preg_match( '/int|decimal|numeric|float|double/i', $primary[0]['Type'] ) ? $primary[0]['Field'] : '';
	}

	private function export_rows( $table, $state, $limit ) {
		global $wpdb;

		if ( ! empty( $state['primary_key'] ) ) {
			$pk = $state['primary_key'];
			if ( null === $state['last_pk'] ) {
				return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $pk, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $pk, $state['last_pk'], $pk, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, (int) $state['offset'], $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function append_sql_part( &$state, $sql ) {
		$max_part_size = 64 * 1024 * 1024;
		$parts_dir     = isset( $state['parts_dir'] ) ? $state['parts_dir'] : '';
		if ( ! $parts_dir ) {
			throw new RuntimeException( esc_html__( 'Database export state is missing the parts folder.', 'backupflow' ) );
		}

		$current = end( $state['parts'] );
		$path    = $current ? $current['path'] : '';
		if ( ! $path || ( file_exists( $path ) && filesize( $path ) >= $max_part_size ) ) {
			$state['part_index'] = (int) $state['part_index'] + 1;
			$name                = sprintf( 'database-%06d.sql', $state['part_index'] );
			$path                = trailingslashit( $parts_dir ) . $name;
			$state['parts'][]    = array(
				'name' => 'database/' . $name,
				'path' => $path,
				'size' => 0,
			);
			$header = "-- BackupFlow database part\n-- Created: " . gmdate( 'c' ) . "\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n";
			file_put_contents( $path, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		file_put_contents( $path, $sql, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$last = count( $state['parts'] ) - 1;
		if ( $last >= 0 ) {
			clearstatcache( true, $path );
			$state['parts'][ $last ]['size'] = file_exists( $path ) ? filesize( $path ) : 0;
		}
	}

	private function insert_sql( $table, $columns, $rows ) {
		$table_sql  = $this->quote_identifier( $table );
		$column_sql = '(' . implode( ', ', array_map( array( $this, 'quote_identifier' ), $columns ) ) . ')';
		$lines      = array();

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column ) {
				$values[] = $this->sql_value( array_key_exists( $column, $row ) ? $row[ $column ] : null );
			}
			$lines[] = '(' . implode( ', ', $values ) . ')';
		}

		return $lines ? 'INSERT INTO ' . $table_sql . ' ' . $column_sql . " VALUES\n" . implode( ",\n", $lines ) . ";\n" : '';
	}

	private function sql_value( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		return "'" . $wpdb->_real_escape( (string) $value ) . "'";
	}

	private function prepare_import_session() {
		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET UNIQUE_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function finish_import_session() {
		global $wpdb;

		$wpdb->query( 'SET UNIQUE_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function rewrite_statement_prefix( $statement, $state ) {
		$source      = isset( $state['source_prefix'] ) ? (string) $state['source_prefix'] : '';
		$destination = isset( $state['destination_prefix'] ) ? (string) $state['destination_prefix'] : '';

		if ( '' === $source || '' === $destination || $source === $destination ) {
			return $statement;
		}

		return str_replace( '`' . $source, '`' . $destination, $statement );
	}

	private function query_import_statement( $statement ) {
		global $wpdb;

		$query                = $this->foreign_key_safe_create_statement( $statement );
		$foreign_key_fallback = $query !== $statement;
		$wpdb->last_error = '';
		$result           = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restore intentionally executes SQL generated by BackupFlow.

		if ( false !== $result ) {
			return array( 'foreign_key_fallback' => $foreign_key_fallback );
		}

		$error = $wpdb->last_error;
		if ( ! $foreign_key_fallback && $this->is_foreign_key_create_error( $statement, $error ) ) {
			$fallback = $this->strip_foreign_key_constraints( $statement );
			if ( $fallback && $fallback !== $statement ) {
				$wpdb->last_error = '';
				$result           = $wpdb->query( $fallback ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restore retries the same BackupFlow SQL without rejected foreign-key clauses.
				if ( false !== $result ) {
					return array( 'foreign_key_fallback' => true );
				}
			}
		}

		throw new RuntimeException( esc_html( $wpdb->last_error ? $wpdb->last_error : __( 'Database import failed.', 'backupflow' ) ) );
	}

	private function foreign_key_safe_create_statement( $statement ) {
		if ( ! preg_match( '/^\s*CREATE\s+TABLE\b/i', $statement ) || ! preg_match( '/\bFOREIGN\s+KEY\b|\bREFERENCES\b/i', $statement ) ) {
			return $statement;
		}

		return $this->strip_foreign_key_constraints( $statement );
	}

	private function is_foreign_key_create_error( $statement, $error ) {
		if ( ! preg_match( '/^\s*CREATE\s+TABLE\b/i', $statement ) ) {
			return false;
		}

		return (bool) preg_match( '/foreign key|errno:\s*150|cannot add constraint|referencing column/i', (string) $error );
	}

	private function strip_foreign_key_constraints( $statement ) {
		$open = strpos( $statement, '(' );
		if ( false === $open ) {
			return $statement;
		}

		$close = $this->find_matching_parenthesis( $statement, $open );
		if ( false === $close ) {
			return $this->strip_foreign_key_lines( $statement );
		}

		$prefix      = substr( $statement, 0, $open + 1 );
		$definitions = substr( $statement, $open + 1, $close - $open - 1 );
		$suffix      = substr( $statement, $close );
		$items       = $this->split_sql_definitions( $definitions );
		if ( ! $items ) {
			return $this->strip_foreign_key_lines( $statement );
		}

		$kept = array();
		foreach ( $items as $item ) {
			if ( preg_match( '/\bFOREIGN\s+KEY\b|\bREFERENCES\b/i', $item ) ) {
				continue;
			}
			$kept[] = rtrim( $item );
		}

		if ( count( $kept ) === count( $items ) ) {
			return $statement;
		}

		return rtrim( $prefix ) . "\n" . implode( ",\n", $kept ) . "\n" . ltrim( $suffix );
	}

	private function strip_foreign_key_lines( $statement ) {
		$lines = preg_split( '/\r\n|\r|\n/', $statement );
		if ( ! is_array( $lines ) ) {
			return $statement;
		}

		$kept = array();
		$skip = false;
		foreach ( $lines as $line ) {
			if ( preg_match( '/\bFOREIGN\s+KEY\b|\bREFERENCES\b/i', $line ) ) {
				$skip = true;
				continue;
			}

			if ( $skip && preg_match( '/^\s*(ON\s+(DELETE|UPDATE)|MATCH\b)/i', $line ) ) {
				continue;
			}

			$skip   = false;
			$kept[] = $line;
		}

		$sql = implode( "\n", $kept );
		$sql = preg_replace( '/,\s*(\)\s*(?:ENGINE|DEFAULT|COMMENT|ROW_FORMAT|AUTO_INCREMENT|\;))/i', '$1', $sql );
		$sql = preg_replace( '/,\s*(\)\s*$)/m', '$1', $sql );

		return $sql;
	}

	private function find_matching_parenthesis( $sql, $open ) {
		$length = strlen( $sql );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$prev = $i > 0 ? $sql[ $i - 1 ] : '';

			if ( $quote ) {
				if ( $char === $quote && '\\' !== $prev ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
				continue;
			}

			if ( ')' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return false;
	}

	private function split_sql_definitions( $sql ) {
		$items  = array();
		$buffer = '';
		$length = strlen( $sql );
		$depth  = 0;
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$prev = $i > 0 ? $sql[ $i - 1 ] : '';

			if ( $quote ) {
				$buffer .= $char;
				if ( $char === $quote && '\\' !== $prev ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
				$buffer .= $char;
				continue;
			}

			if ( ')' === $char && $depth > 0 ) {
				$depth--;
				$buffer .= $char;
				continue;
			}

			if ( ',' === $char && 0 === $depth ) {
				if ( '' !== trim( $buffer ) ) {
					$items[] = trim( $buffer );
				}
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		if ( '' !== trim( $buffer ) ) {
			$items[] = trim( $buffer );
		}

		return $items;
	}

	private function replace_value( $value, $from, $to ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$unserialized = maybe_unserialize( $value );
		if ( $unserialized !== $value || is_serialized( $value ) ) {
			$replaced = $this->recursive_replace( $unserialized, $from, $to );
			return maybe_serialize( $replaced );
		}

		return str_replace( $from, $to, $value );
	}

	private function replace_value_pairs( $value, $pairs ) {
		foreach ( $pairs as $pair ) {
			$value = $this->replace_value( $value, $pair[0], $pair[1] );
		}

		return $value;
	}

	private function recursive_replace( $value, $from, $to ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$new_key = is_string( $key ) ? str_replace( $from, $to, $key ) : $key;
				if ( $new_key !== $key ) {
					unset( $value[ $key ] );
				}
				$value[ $new_key ] = $this->recursive_replace( $item, $from, $to );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value->{$key} = $this->recursive_replace( $item, $from, $to );
			}
			return $value;
		}

		return is_string( $value ) ? str_replace( $from, $to, $value ) : $value;
	}
}
