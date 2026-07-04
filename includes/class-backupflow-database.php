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

		$header = "-- BackupFlow database export\n-- Site: " . home_url() . "\n-- Created: " . gmdate( 'c' ) . "\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\n\n";
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

		return $file_path;
	}

	public function import_file( $file_path, $progress_callback = null ) {
		global $wpdb;

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new RuntimeException( esc_html__( 'Database SQL file is missing or unreadable.', 'backupflow' ) );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not open the SQL file.', 'backupflow' ) );
		}

		$statement = '';
		$count     = 0;

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				continue;
			}

			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
				continue;
			}

			$statement .= $line;
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$query = trim( $statement );
				if ( $query ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restore intentionally executes trusted SQL generated by BackupFlow.
					$result = $wpdb->query( $query );
					if ( false === $result ) {
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						throw new RuntimeException( esc_html( $wpdb->last_error ? $wpdb->last_error : __( 'Database import failed.', 'backupflow' ) ) );
					}
					$count++;
					if ( is_callable( $progress_callback ) && 0 === $count % 25 ) {
						call_user_func( $progress_callback, $count );
					}
				}
				$statement = '';
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}

	public function search_replace_url( $from, $to, $progress_callback = null ) {
		global $wpdb;

		$from = untrailingslashit( (string) $from );
		$to   = untrailingslashit( (string) $to );
		if ( '' === $from || '' === $to || $from === $to ) {
			return 0;
		}

		$changed = 0;
		$tables  = $this->get_tables();

		foreach ( $tables as $table ) {
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
				continue;
			}

			$select_cols = array_unique( array_merge( $keys, $text_cols ) );
			$select_sql  = implode( ', ', array_map( array( $this, 'quote_identifier' ), $select_cols ) );
			$offset      = 0;
			$limit       = 200;

			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column identifiers are read from the database schema and quoted before chunked migration rewrite.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$select_sql} FROM %i LIMIT %d, %d", $table, $offset, $limit ), ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$updates = array();
					foreach ( $text_cols as $column ) {
						if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
							continue;
						}

						$new = $this->replace_value( $row[ $column ], $from, $to );
						if ( $new !== $row[ $column ] ) {
							$updates[ $column ] = $new;
						}
					}

					if ( $updates ) {
						$where = array();
						foreach ( $keys as $key ) {
							$where[ $key ] = $row[ $key ];
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration rewrite updates serialized URL values in-place.
						$wpdb->update( $table, $updates, $where );
						$changed++;
					}
				}

				$offset += $limit;
				if ( is_callable( $progress_callback ) ) {
					call_user_func( $progress_callback, $table, $changed );
				}
			} while ( count( (array) $rows ) === $limit );
		}

		update_option( 'home', $to );
		update_option( 'siteurl', $to );

		return $changed;
	}

	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	private function sql_value( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		return "'" . $wpdb->_real_escape( (string) $value ) . "'";
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
