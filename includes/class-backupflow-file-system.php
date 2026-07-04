<?php
/**
 * Zip and filesystem helpers.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_File_System {
	public function prepare_file_scan_state( $list_path, $root, $exclude_paths = array() ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		$dir  = dirname( $list_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'Could not create temporary file list folder.', 'backupflow' ) );
		}

		$handle = fopen( $list_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not create the temporary file list.', 'backupflow' ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'list_path'      => $list_path,
			'root'           => $root,
			'exclude_paths'  => is_array( $exclude_paths ) ? array_values( $exclude_paths ) : array(),
			'stack'          => array( '' ),
			'current_dir'    => null,
			'current_offset' => 0,
			'total'          => 0,
			'excluded'       => 0,
			'bytes_total'    => 0,
			'done'           => false,
		);
	}

	public function scan_files_chunk( $state, $time_limit = 8, $progress_callback = null ) {
		$state      = is_array( $state ) ? $state : array();
		$started_at = microtime( true );
		$time_limit = max( 2, (int) $time_limit );

		if ( ! empty( $state['done'] ) ) {
			return $state;
		}

		$root      = isset( $state['root'] ) ? trailingslashit( wp_normalize_path( $state['root'] ) ) : trailingslashit( wp_normalize_path( ABSPATH ) );
		$list_path = isset( $state['list_path'] ) ? $state['list_path'] : '';
		if ( ! $list_path ) {
			throw new RuntimeException( esc_html__( 'The file scan state is missing its file list.', 'backupflow' ) );
		}

		$handle = fopen( $list_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not update the temporary file list.', 'backupflow' ) );
		}

		while ( true ) {
			if ( null === $state['current_dir'] ) {
				if ( empty( $state['stack'] ) ) {
					$state['done'] = true;
					break;
				}
				$state['current_dir']    = array_pop( $state['stack'] );
				$state['current_offset'] = 0;
			}

			$dir = $root . ltrim( (string) $state['current_dir'], '/' );
			if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
				$state['current_dir'] = null;
				continue;
			}

			$offset = 0;
			try {
				$iterator = new DirectoryIterator( $dir );
				foreach ( $iterator as $entry ) {
					if ( $entry->isDot() ) {
						continue;
					}

					if ( $offset++ < (int) $state['current_offset'] ) {
						continue;
					}

					$rel = trim( (string) $state['current_dir'], '/' );
					$rel = $rel ? $rel . '/' . $entry->getFilename() : $entry->getFilename();
					$rel = backupflow_clean_path( $rel );
					$state['current_offset'] = $offset;

					if ( $entry->isLink() ) {
						continue;
					}

					if ( $entry->isDir() ) {
						if ( ! $this->is_excluded( $rel, $state['exclude_paths'] ) ) {
							$state['stack'][] = $rel;
						}
						continue;
					}

					if ( ! $entry->isFile() ) {
						continue;
					}

					if ( $this->is_excluded( $rel, $state['exclude_paths'] ) ) {
						$state['excluded']++;
						continue;
					}

					fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					$state['total']++;
					$state['bytes_total'] += (int) $entry->getSize();

					if ( is_callable( $progress_callback ) && 0 === (int) $state['total'] % 500 ) {
						call_user_func( $progress_callback, (int) $state['total'], (int) $state['excluded'] );
					}

					if ( microtime( true ) - $started_at >= $time_limit ) {
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return $state;
					}
				}
			} catch ( Throwable $e ) {
				$state['current_dir'] = null;
				continue;
			}

			$state['current_dir']    = null;
			$state['current_offset'] = 0;

			if ( microtime( true ) - $started_at >= $time_limit ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $state;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $state;
	}

	public function build_file_list( $list_path, $root, $exclude_paths = array(), $progress_callback = null ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		$dir  = dirname( $list_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'Could not create temporary file list folder.', 'backupflow' ) );
		}

		$handle = fopen( $list_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not create the temporary file list.', 'backupflow' ) );
		}

		$count    = 0;
		$excluded = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			$rel  = backupflow_rel_path( $path, $root );

			if ( $this->is_excluded( $rel, $exclude_paths ) ) {
				$excluded++;
				continue;
			}

			fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$count++;

			if ( is_callable( $progress_callback ) && 0 === $count % 500 ) {
				call_user_func( $progress_callback, $count, $excluded );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'total'    => $count,
			'excluded' => $excluded,
		);
	}

	public function add_files_to_zip_chunk( $zip_path, $list_path, $root, $start, $limit ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'The PHP ZipArchive extension is required to create backups.', 'backupflow' ) );
		}

		$root  = trailingslashit( wp_normalize_path( $root ) );
		$zip   = new ZipArchive();
		$flags = file_exists( $zip_path ) ? ZipArchive::CREATE : ZipArchive::CREATE | ZipArchive::OVERWRITE;
		if ( true !== $zip->open( $zip_path, $flags ) ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'backupflow' ) );
		}

		$file    = new SplFileObject( $list_path, 'r' );
		$index   = 0;
		$added   = 0;
		$skipped = 0;

		while ( ! $file->eof() ) {
			$rel = trim( (string) $file->fgets() );
			if ( '' === $rel ) {
				continue;
			}

			if ( $index++ < $start ) {
				continue;
			}

			if ( $added >= $limit ) {
				break;
			}

			$abs = $root . $rel;
			if ( file_exists( $abs ) && is_readable( $abs ) && is_file( $abs ) ) {
				if ( ! $zip->addFile( $abs, 'files/' . $rel ) ) {
					$zip->close();
					throw new RuntimeException( esc_html__( 'Could not add a website file to the backup archive.', 'backupflow' ) );
				}
				$added++;
			} else {
				$skipped++;
			}
		}

		if ( true !== $zip->close() ) {
			throw new RuntimeException( esc_html__( 'Could not finish writing the backup archive.', 'backupflow' ) );
		}

		return array(
			'next'    => $start + $added + $skipped,
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	public function count_zip_file_entries( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) || ! file_exists( $zip_path ) ) {
			return 0;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return 0;
		}

		$count = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( $name && 0 === strpos( $name, 'files/' ) && '/' !== substr( $name, -1 ) ) {
				$count++;
			}
		}

		$zip->close();
		return $count;
	}

	public function list_zip_entries( $zip_path, $prefix = '' ) {
		if ( ! class_exists( 'ZipArchive' ) || ! file_exists( $zip_path ) ) {
			return array();
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array();
		}

		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( ! $name || '/' === substr( $name, -1 ) ) {
				continue;
			}
			if ( $prefix && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$entries[] = $name;
		}
		$zip->close();
		sort( $entries );
		return $entries;
	}

	public function extract_files_from_zip_chunk( $zip_path, $target_root, $zip_index, $limit, $skip_wp_config = true ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'The PHP ZipArchive extension is required to restore backups.', 'backupflow' ) );
		}

		$target_root = trailingslashit( wp_normalize_path( $target_root ) );
		$zip         = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'backupflow' ) );
		}

		$processed = 0;
		$restored  = 0;
		$skipped   = 0;
		$next      = $zip_index;

		for ( $i = $zip_index; $i < $zip->numFiles; $i++ ) {
			$next = $i + 1;
			$name = $zip->getNameIndex( $i );
			if ( ! $name || 0 !== strpos( $name, 'files/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}

			$rel = substr( $name, 6 );
			if ( $skip_wp_config && 'wp-config.php' === strtolower( $rel ) ) {
				$skipped++;
				continue;
			}

			$destination = wp_normalize_path( $target_root . $rel );
			if ( ! backupflow_path_is_inside( $destination, $target_root ) ) {
				$skipped++;
				continue;
			}

			$parent = dirname( $destination );
			if ( ! wp_mkdir_p( $parent ) ) {
				$skipped++;
				continue;
			}

			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				$skipped++;
				continue;
			}

			$out = fopen( $destination, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $out ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$skipped++;
				continue;
			}

			stream_copy_to_stream( $stream, $out );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$processed++;
			$restored++;

			if ( $processed >= $limit ) {
				break;
			}
		}

		$done = $next >= $zip->numFiles;
		$zip->close();

		return array(
			'next'     => $next,
			'restored' => $restored,
			'skipped'  => $skipped,
			'done'     => $done,
		);
	}

	public function add_json_to_zip( $zip_path, $name, $data ) {
		$zip = new ZipArchive();
		$flags = file_exists( $zip_path ) ? ZipArchive::CREATE : ZipArchive::CREATE | ZipArchive::OVERWRITE;
		if ( true !== $zip->open( $zip_path, $flags ) ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'backupflow' ) );
		}

		if ( ! $zip->addFromString( $name, wp_json_encode( $data, JSON_PRETTY_PRINT ) ) ) {
			$zip->close();
			throw new RuntimeException( esc_html__( 'Could not add the backup manifest to the archive.', 'backupflow' ) );
		}
		if ( true !== $zip->close() ) {
			throw new RuntimeException( esc_html__( 'Could not finish writing the backup archive.', 'backupflow' ) );
		}
	}

	public function add_file_to_zip( $zip_path, $source, $name ) {
		$zip = new ZipArchive();
		$flags = file_exists( $zip_path ) ? ZipArchive::CREATE : ZipArchive::CREATE | ZipArchive::OVERWRITE;
		if ( true !== $zip->open( $zip_path, $flags ) ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'backupflow' ) );
		}

		if ( ! $zip->addFile( $source, $name ) ) {
			$zip->close();
			throw new RuntimeException( esc_html__( 'Could not add a file to the backup archive.', 'backupflow' ) );
		}
		if ( true !== $zip->close() ) {
			throw new RuntimeException( esc_html__( 'Could not finish writing the backup archive.', 'backupflow' ) );
		}
	}

	public function extract_zip_entry_to_file( $zip_path, $entry, $destination ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new RuntimeException( esc_html__( 'Could not open the backup archive.', 'backupflow' ) );
		}

		$stream = $zip->getStream( $entry );
		if ( ! $stream ) {
			$zip->close();
			return false;
		}

		wp_mkdir_p( dirname( $destination ) );
		$out = fopen( $destination, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$zip->close();
			return false;
		}

		stream_copy_to_stream( $stream, $out );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();
		return true;
	}

	private function is_excluded( $relative_path, $exclude_paths ) {
		$relative_path = backupflow_clean_path( $relative_path );
		$exclude_paths = is_array( $exclude_paths ) ? $exclude_paths : array();

		foreach ( $exclude_paths as $exclude ) {
			$exclude = trim( backupflow_clean_path( $exclude ) );
			if ( '' === $exclude ) {
				continue;
			}

			if ( $relative_path === $exclude || 0 === strpos( $relative_path, trailingslashit( $exclude ) ) ) {
				return true;
			}
		}

		return false;
	}
}
