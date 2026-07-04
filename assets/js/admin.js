(function ($) {
	'use strict';

	var currentJobId = null;
	var shouldReloadOnClose = false;
	var jobFinished = false;
	var jobCancelled = false;
	var activeUploadRequest = null;
	var currentImportId = null;
	var uploadStartedAt = 0;
	var uploadSpeedEntry = null;

	function post(action, data) {
		return $.post(BackupFlowAdmin.ajaxUrl, $.extend({
			action: action,
			nonce: BackupFlowAdmin.nonce
		}, data || {}));
	}

	function setStep($builder, step) {
		step = String(step);
		$builder.attr('data-current-step', step);
		$builder.find('[data-step]').removeClass('is-active');
		$builder.find('[data-step="' + step + '"]').addClass('is-active');
		$builder.find('[data-step-panel]').removeClass('is-active');
		$builder.find('[data-step-panel="' + step + '"]').addClass('is-active');
	}

	function backupTypeFromBuilder($builder) {
		var files = $builder.find('[data-backup-part="files"]').is(':checked');
		var database = $builder.find('[data-backup-part="database"]').is(':checked');

		if (files && database) {
			return 'full';
		}

		if (files) {
			return 'files';
		}

		if (database) {
			return 'database';
		}

		window.alert('Select files, database, or both before creating a backup.');
		return '';
	}

	function selectedDestination($builder) {
		var $selected = $builder.find('.backupflow-destination.is-selected').first();
		return $selected.length ? $selected.data('destination') : 'local';
	}

	function openModal(title) {
		var $modal = $('.backupflow-modal');
		shouldReloadOnClose = false;
		jobFinished = false;
		jobCancelled = false;
		activeUploadRequest = null;
		currentImportId = null;
		uploadStartedAt = 0;
		uploadSpeedEntry = null;
		$modal.find('#backupflow-modal-title').text(title || BackupFlowAdmin.strings.working);
		$modal.find('[data-job-message]').text(BackupFlowAdmin.strings.working);
		$modal.find('.backupflow-progress span').css('width', '0%');
		$modal.find('.backupflow-progress strong').text('0%');
		$modal.find('[data-job-log]').empty();
		$modal.find('[data-cancel-confirm]').prop('hidden', true);
		$modal.find('[data-backup-download]').prop('hidden', true).attr('href', '#');
		$modal.find('[data-dashboard-link]').prop('hidden', true).attr('href', BackupFlowAdmin.dashboardUrl || '#');
		$modal.find('[data-job-close]').prop('disabled', false).text(BackupFlowAdmin.strings.cancel || 'Cancel');
		$modal.prop('hidden', false);
	}

	function renderJob(job) {
		var progress = Math.max(0, Math.min(100, parseInt(job.progress || 0, 10)));
		var $modal = $('.backupflow-modal');
		var $log = $modal.find('[data-job-log]');

		$modal.find('[data-job-message]').text(job.message || '');
		$modal.find('.backupflow-progress span').css('width', progress + '%');
		$modal.find('.backupflow-progress strong').text(progress + '%');

		$log.empty();
		(job.logs || []).forEach(function (entry) {
			$('<div/>', {
				'class': 'is-' + (entry.level || 'info')
			}).append(
				$('<span/>').text(entry.time || ''),
				$('<strong/>').text(entry.message || '')
			).appendTo($log);
		});
		$log.scrollTop($log.prop('scrollHeight'));
	}

	function currentTime() {
		var now = new Date();
		return String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
	}

	function appendLog(message, level) {
		var $log = $('.backupflow-modal').find('[data-job-log]');
		var $row = $('<div/>', {
			'class': 'is-' + (level || 'info')
		}).append(
			$('<span/>').text(currentTime()),
			$('<strong/>').text(message || '')
		);

		$row.appendTo($log);
		$log.scrollTop($log.prop('scrollHeight'));
		return $row;
	}

	function formatBytes(bytes) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var value = Math.max(0, parseFloat(bytes || 0));
		var index = 0;

		while (value >= 1024 && index < units.length - 1) {
			value = value / 1024;
			index++;
		}

		return (index === 0 ? value.toFixed(0) : value.toFixed(2)) + ' ' + units[index];
	}

	function updateUploadSpeed(done, total) {
		var elapsed = uploadStartedAt ? Math.max(0.25, (Date.now() - uploadStartedAt) / 1000) : 0.25;
		var speed = done / elapsed;
		var message = 'Uploaded ' + formatBytes(done) + ' of ' + formatBytes(total) + ' at ' + formatBytes(speed) + '/s.';

		if (!uploadSpeedEntry || !uploadSpeedEntry.length) {
			uploadSpeedEntry = appendLog(message, 'info');
			return;
		}

		uploadSpeedEntry.find('span').text(currentTime());
		uploadSpeedEntry.find('strong').text(message);
	}

	function processJob(jobId) {
		if (jobCancelled) {
			return;
		}

		post('backupflow_process_job', { job_id: jobId }).done(function (response) {
			if (jobCancelled) {
				return;
			}

			if (!response || !response.success || !response.data.job) {
				finishModal(false, BackupFlowAdmin.strings.processFailed || 'BackupFlow could not finish this process. Please try again.');
				return;
			}

			var job = response.data.job;
			renderJob(job);

			if (job.status === 'complete') {
				finishModal(true, job.message || BackupFlowAdmin.strings.complete, job);
				return;
			}

			if (job.status === 'failed') {
				finishModal(false, job.message || BackupFlowAdmin.strings.failed);
				return;
			}

			if (job.status === 'cancelled') {
				finishModal(false, job.message || BackupFlowAdmin.strings.cancelled);
				return;
			}

			window.setTimeout(function () {
				processJob(jobId);
			}, 500);
		}).fail(function (xhr) {
			if (jobCancelled) {
				return;
			}

			var message = BackupFlowAdmin.strings.processFailed || 'BackupFlow could not finish this process. Please try again.';
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			finishModal(false, message);
		});
	}

	function finishModal(ok, message, job) {
		var $modal = $('.backupflow-modal');
		var backup = job && job.type === 'backup' && job.result && job.result.backup ? job.result.backup : null;
		jobFinished = true;
		$modal.find('[data-job-message]').text(message || (ok ? BackupFlowAdmin.strings.complete : BackupFlowAdmin.strings.failed));
		$modal.find('[data-cancel-confirm]').prop('hidden', true);
		$modal.find('[data-job-close]').prop('disabled', false).text(BackupFlowAdmin.strings.close || 'Close');
		$modal.find('[data-dashboard-link]').prop('hidden', !ok || !backup).attr('href', BackupFlowAdmin.dashboardUrl || '#');
		$modal.find('[data-backup-download]').prop('hidden', !ok || !backup || !backup.download_url).attr('href', backup && backup.download_url ? backup.download_url : '#');
		shouldReloadOnClose = true;
	}

	function closeModal(reload) {
		$('.backupflow-modal').prop('hidden', true).find('[data-cancel-confirm]').prop('hidden', true);
		currentJobId = null;
		if (reload) {
			window.location.reload();
		}
	}

	function cancelCurrentJob() {
		jobCancelled = true;

		if (activeUploadRequest) {
			activeUploadRequest.abort();
			activeUploadRequest = null;
		}

		if (currentImportId) {
			post('backupflow_cancel_import', { import_id: currentImportId });
			currentImportId = null;
		}

		if (currentJobId) {
			post('backupflow_cancel_job', { job_id: currentJobId });
		}

		closeModal(false);
	}

	function setUploadProgress(percent, message) {
		var $modal = $('.backupflow-modal');
		var progress = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
		$modal.find('[data-job-message]').text(message || BackupFlowAdmin.strings.uploading || 'Uploading backup');
		$modal.find('.backupflow-progress span').css('width', progress + '%');
		$modal.find('.backupflow-progress strong').text(progress + '%');
	}

	function preflightMessage(response, fallback) {
		var preflight = response && response.data && response.data.preflight ? response.data.preflight : null;
		var lines = [];

		if (preflight && preflight.message) {
			lines.push(preflight.message);
		}

		if (preflight && preflight.checks) {
			preflight.checks.forEach(function (check) {
				if (check.status !== 'ready') {
					lines.push(check.label + ': ' + check.message);
				}
			});
		}

		return lines.length ? lines.join('\n') : fallback;
	}

	function runPreflight(context, destination, fileSize, backupType) {
		return post('backupflow_preflight', {
			context: context || 'backup',
			destination: destination || 'local',
			file_size: fileSize || 0,
			backup_type: backupType || 'full'
		});
	}

	function uploadChunk(importId, file, chunkSize, index, offset, restoreMode, retryCount) {
		var end = Math.min(file.size, offset + chunkSize);
		var formData = new FormData();
		var request = new XMLHttpRequest();
		retryCount = retryCount || 0;

		formData.append('action', 'backupflow_upload_chunk');
		formData.append('nonce', BackupFlowAdmin.nonce);
		formData.append('import_id', importId);
		formData.append('chunk_index', index);
		formData.append('chunk', file.slice(offset, end), file.name + '.part');

		activeUploadRequest = request;
		request.upload.addEventListener('progress', function (event) {
			if (!event.lengthComputable || !file.size) {
				return;
			}
			setUploadProgress(Math.round(((offset + event.loaded) / file.size) * 100), BackupFlowAdmin.strings.uploading || 'Uploading backup');
			updateUploadSpeed(offset + event.loaded, file.size);
		});

		request.onreadystatechange = function () {
			var response;
			var nextOffset;

			if (request.readyState !== 4 || jobCancelled) {
				return;
			}

			activeUploadRequest = null;

			try {
				response = JSON.parse(request.responseText);
			} catch (error) {
				finishModal(false, BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.');
				return;
			}

			if (request.status < 200 || request.status >= 300 || !response || !response.success) {
				if (retryCount < 3) {
					appendLog('Upload paused. Retrying this chunk...', 'warning');
					window.setTimeout(function () {
						uploadChunk(importId, file, chunkSize, index, offset, restoreMode, retryCount + 1);
					}, 900);
					return;
				}
				finishModal(false, response && response.data && response.data.message ? response.data.message : BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.');
				return;
			}

			nextOffset = response.data && response.data.received ? parseInt(response.data.received, 10) : end;
			if (index === 0 || nextOffset >= file.size || (index + 1) % 10 === 0) {
				appendLog('Uploaded chunk ' + (index + 1) + '.', 'success');
			}
			if (nextOffset < file.size) {
				uploadChunk(importId, file, chunkSize, index + 1, nextOffset, restoreMode, 0);
				return;
			}

			setUploadProgress(100, restoreMode === 'upload' ? (BackupFlowAdmin.strings.uploadStored || 'Backup uploaded and added to Restore Points.') : (BackupFlowAdmin.strings.uploadComplete || 'Backup uploaded. Starting restore...'));
			post('backupflow_complete_import', {
				import_id: importId
			}).done(function (completeResponse) {
				if (!completeResponse || !completeResponse.success || !completeResponse.data.backup) {
					finishModal(false, BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.');
					return;
				}
				currentImportId = null;
				appendLog('Backup added to Restore Points.', 'success');
				if (restoreMode === 'upload') {
					finishModal(true, BackupFlowAdmin.strings.uploadStored || 'Backup uploaded and added to Restore Points.', {
						type: 'backup',
						result: {
							backup: completeResponse.data.backup
						}
					});
					return;
				}
				startRestore(completeResponse.data.backup.id, restoreMode, true);
			}).fail(function (xhr) {
				var message = BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				finishModal(false, message);
			});
		};

		request.open('POST', BackupFlowAdmin.ajaxUrl, true);
		request.send(formData);
	}

	function uploadAndRestore(file, restoreMode) {
		if (!file) {
			window.alert(BackupFlowAdmin.strings.chooseBackup || 'Choose a backup ZIP first.');
			return;
		}

		openModal(BackupFlowAdmin.strings.importPreparing || 'Preparing secure upload');
		setUploadProgress(0, BackupFlowAdmin.strings.importPreparing || 'Preparing secure upload');
		appendLog('Checking server readiness for upload.', 'info');

		runPreflight('import', 'local', file.size || 0).done(function (preflightResponse) {
			if (!preflightResponse || !preflightResponse.success || !preflightResponse.data.preflight || !preflightResponse.data.preflight.ready) {
				finishModal(false, preflightMessage(preflightResponse, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
				return;
			}

			post('backupflow_start_import', {
				file_name: file.name,
				file_size: file.size || 0
			}).done(function (response) {
				if (!response || !response.success || !response.data.import_id) {
					finishModal(false, BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.');
					return;
				}
				currentImportId = response.data.import_id;
				uploadStartedAt = Date.now();
				appendLog(BackupFlowAdmin.strings.uploadSessionReady || 'Upload session ready.', 'success');
				uploadChunk(response.data.import_id, file, response.data.chunk_size || (4 * 1024 * 1024), 0, response.data.received || 0, restoreMode, 0);
			}).fail(function (xhr) {
				var message = BackupFlowAdmin.strings.uploadFailed || 'Backup upload failed. Choose a valid BackupFlow ZIP and try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				finishModal(false, message);
			});
		}).fail(function (xhr) {
			finishModal(false, preflightMessage(xhr.responseJSON, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
		});
	}

	function initTables() {
		$('.backupflow-table').each(function () {
			var $table = $(this);
			if ($table.data('backupflowPaged')) {
				return;
			}

			var $tbody = $table.find('tbody');
			var pageSize = parseInt($table.data('page-size') || 10, 10);
			var state = {
				page: 1,
				query: ''
			};
			var $tools = $('<div/>', { 'class': 'backupflow-table-tools' }).append(
				$('<label/>').append(
					$('<span/>').text('Search backups'),
					$('<input/>', {
						type: 'search',
						placeholder: 'Search backups'
					})
				),
				$('<strong/>', { 'class': 'backupflow-table-count' })
			);
			var $pager = $('<div/>', { 'class': 'backupflow-pagination' }).append(
				$('<button/>', { type: 'button', 'data-page-prev': '1', text: 'Previous' }),
				$('<span/>'),
				$('<button/>', { type: 'button', 'data-page-next': '1', text: 'Next' })
			);

			function rows() {
				return $tbody.find('tr');
			}

			function matchedRows() {
				var query = state.query.toLowerCase();
				return rows().filter(function () {
					return !query || $(this).text().toLowerCase().indexOf(query) !== -1;
				});
			}

			function render() {
				var $rows = rows();
				var $matched = matchedRows();
				var total = $matched.length;
				var pages = Math.max(1, Math.ceil(total / pageSize));
				var start;
				var end;

				state.page = Math.min(Math.max(1, state.page), pages);
				start = (state.page - 1) * pageSize;
				end = start + pageSize;

				$rows.hide();
				$matched.slice(start, end).show();
				$tools.find('.backupflow-table-count').text(total + ' item' + (total === 1 ? '' : 's'));
				$pager.find('span').text('Page ' + state.page + ' of ' + pages);
				$pager.find('[data-page-prev]').prop('disabled', state.page <= 1);
				$pager.find('[data-page-next]').prop('disabled', state.page >= pages);
				$pager.toggle(total > pageSize);
				$tools.toggle($rows.length > pageSize);
			}

			$table.before($tools);
			$table.after($pager);
			$table.data('backupflowPaged', true);
			$table.data('backupflowRender', render);

			$tools.on('input', 'input', function () {
				state.query = $(this).val();
				state.page = 1;
				render();
			});

			$pager.on('click', '[data-page-prev]', function () {
				state.page -= 1;
				render();
			});

			$pager.on('click', '[data-page-next]', function () {
				state.page += 1;
				render();
			});

			render();
		});
	}

	function startBackup(type, destination) {
		if (!type) {
			return;
		}

		openModal('Checking backup readiness');
		runPreflight('backup', destination || 'local', 0, type).done(function (preflightResponse) {
			if (!preflightResponse || !preflightResponse.success || !preflightResponse.data.preflight || !preflightResponse.data.preflight.ready) {
				finishModal(false, preflightMessage(preflightResponse, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
				return;
			}

			$('.backupflow-modal').find('#backupflow-modal-title').text('Backup in progress');
			post('backupflow_start_backup', {
				backup_type: type,
				destination: destination || 'local'
			}).done(function (response) {
				if (jobCancelled) {
					return;
				}

				if (!response || !response.success || !response.data.job) {
					finishModal(false, BackupFlowAdmin.strings.backupFailed || 'BackupFlow could not start the backup. Please try again.');
					return;
				}

				currentJobId = response.data.job.id;
				renderJob(response.data.job);
				processJob(currentJobId);
			}).fail(function (xhr) {
				if (jobCancelled) {
					return;
				}

				var message = BackupFlowAdmin.strings.backupFailed || 'BackupFlow could not start the backup. Please try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				finishModal(false, message);
			});
		}).fail(function (xhr) {
			finishModal(false, preflightMessage(xhr.responseJSON, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
		});
	}

	function startRestore(backupId, restoreMode, keepModal) {
		if (!keepModal) {
			openModal('Checking restore readiness');
		} else {
			$('.backupflow-modal').find('#backupflow-modal-title').text('Restore in progress');
		}
		runPreflight('restore', 'local').done(function (preflightResponse) {
			if (!preflightResponse || !preflightResponse.success || !preflightResponse.data.preflight || !preflightResponse.data.preflight.ready) {
				finishModal(false, preflightMessage(preflightResponse, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
				return;
			}

			$('.backupflow-modal').find('#backupflow-modal-title').text('Restore in progress');
			post('backupflow_start_restore', {
				backup_id: backupId,
				restore_mode: restoreMode || 'full'
			}).done(function (response) {
				if (jobCancelled) {
					return;
				}

				if (!response || !response.success || !response.data.job) {
					finishModal(false, BackupFlowAdmin.strings.restoreFailed || 'BackupFlow could not start the restore. Please try again.');
					return;
				}

				currentJobId = response.data.job.id;
				renderJob(response.data.job);
				processJob(currentJobId);
			}).fail(function (xhr) {
				if (jobCancelled) {
					return;
				}

				var message = BackupFlowAdmin.strings.restoreFailed || 'BackupFlow could not start the restore. Please try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				finishModal(false, message);
			});
		}).fail(function (xhr) {
			finishModal(false, preflightMessage(xhr.responseJSON, BackupFlowAdmin.strings.preflightFailed || 'BackupFlow needs attention before this job can run.'));
		});
	}

	$(document).on('click', '.backupflow-next-step', function () {
		var $builder = $(this).closest('.backupflow-builder');
		setStep($builder, $(this).data('next-step'));
	});

	$(document).on('click', '.backupflow-stepper [data-step]', function () {
		setStep($(this).closest('.backupflow-builder'), $(this).data('step'));
	});

	$(document).on('click', '.backupflow-destination', function () {
		if ($(this).is(':disabled') || $(this).hasClass('is-disabled')) {
			return;
		}

		$(this).closest('.backupflow-destination-grid').find('.backupflow-destination').removeClass('is-selected');
		$(this).addClass('is-selected');
	});

	$(document).on('click', '.backupflow-run-builder', function () {
		var $builder = $(this).closest('.backupflow-builder');
		startBackup(backupTypeFromBuilder($builder), selectedDestination($builder));
	});

	$(document).on('click', '.backupflow-start-backup', function () {
		startBackup($(this).data('backup-type') || 'full', $(this).data('destination') || 'local');
	});

	$(document).on('click', '.backupflow-restore-backup', function () {
		if (!window.confirm(BackupFlowAdmin.strings.restoreConfirm)) {
			return;
		}
		startRestore($(this).data('backup-id'), 'full');
	});

	$(document).on('change', '[data-import-file]', function () {
		var file = this.files && this.files.length ? this.files[0] : null;
		var $uploader = $(this).closest('[data-import-uploader]');

		if (!file) {
			$uploader.removeData('backupFile');
			$uploader.find('[data-selected-file], [data-restore-choice]').prop('hidden', true);
			return;
		}

		$uploader.data('backupFile', file);
		$uploader.find('[data-selected-file]').text(file.name).prop('hidden', false);
		$uploader.find('[data-restore-choice]').prop('hidden', false);
	});

	$(document).on('dragover dragenter', '[data-import-uploader]', function (event) {
		event.preventDefault();
		$(this).addClass('is-dragging');
	});

	$(document).on('dragleave drop', '[data-import-uploader]', function (event) {
		event.preventDefault();
		$(this).removeClass('is-dragging');
	});

	$(document).on('drop', '[data-import-uploader]', function (event) {
		var files = event.originalEvent.dataTransfer && event.originalEvent.dataTransfer.files;
		var input = $(this).find('[data-import-file]').get(0);

		if (!files || !files.length || !input) {
			return;
		}

		$(this).data('backupFile', files[0]);
		$(this).find('[data-selected-file]').text(files[0].name).prop('hidden', false);
		$(this).find('[data-restore-choice]').prop('hidden', false);
	});

	$(document).on('click', '[data-import-restore-mode]', function () {
		var $uploader = $(this).closest('[data-import-uploader]');
		var input = $uploader.find('[data-import-file]').get(0);
		var file = $uploader.data('backupFile') || (input && input.files && input.files.length ? input.files[0] : null);
		uploadAndRestore(file, $(this).data('import-restore-mode') || 'full');
	});

	$(document).on('click', '.backupflow-delete-backup', function () {
		var backupId = $(this).data('backup-id');
		var $row = $('[data-backup-row="' + backupId + '"]');

		if (!window.confirm(BackupFlowAdmin.strings.deleteConfirm)) {
			return;
		}

		post('backupflow_delete_backup', { backup_id: backupId }).done(function () {
			$row.fadeOut(160, function () {
				var $table = $row.closest('.backupflow-table');
				$(this).remove();
				if ($table.data('backupflowRender')) {
					$table.data('backupflowRender')();
				}
			});
		});
	});

	$(document).on('click', '[data-job-close]', function () {
		if (jobFinished) {
			closeModal(shouldReloadOnClose);
			return;
		}

		$('.backupflow-modal').find('[data-cancel-confirm]').prop('hidden', false);
	});

	$(document).on('click', '[data-cancel-no]', function () {
		$('.backupflow-modal').find('[data-cancel-confirm]').prop('hidden', true);
	});

	$(document).on('click', '[data-cancel-yes]', function () {
		cancelCurrentJob();
	});

	$(initTables);
})(jQuery);
