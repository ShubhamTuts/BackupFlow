(function ($) {
	'use strict';

	var currentJobId = null;
	var shouldReloadOnClose = false;
	var jobFinished = false;
	var jobCancelled = false;
	var activeUploadRequest = null;

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

	function processJob(jobId) {
		if (jobCancelled) {
			return;
		}

		post('backupflow_process_job', { job_id: jobId }).done(function (response) {
			if (jobCancelled) {
				return;
			}

			if (!response || !response.success || !response.data.job) {
				finishModal(false, 'Process failed.');
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
			var message = 'Process failed.';
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

	function uploadAndRestore(file, restoreMode) {
		var formData = new FormData();
		var request = new XMLHttpRequest();

		if (!file) {
			window.alert(BackupFlowAdmin.strings.chooseBackup || 'Choose a backup ZIP first.');
			return;
		}

		openModal(BackupFlowAdmin.strings.uploading || 'Uploading backup');
		setUploadProgress(0, BackupFlowAdmin.strings.uploading || 'Uploading backup');

		formData.append('action', 'backupflow_import_backup');
		formData.append('nonce', BackupFlowAdmin.nonce);
		formData.append('backupflow_import', file);

		activeUploadRequest = request;
		request.upload.addEventListener('progress', function (event) {
			if (!event.lengthComputable) {
				return;
			}
			setUploadProgress(Math.round((event.loaded / event.total) * 100), BackupFlowAdmin.strings.uploading || 'Uploading backup');
		});

		request.onreadystatechange = function () {
			var response;

			if (request.readyState !== 4 || jobCancelled) {
				return;
			}

			activeUploadRequest = null;

			if (request.status < 200 || request.status >= 300) {
				finishModal(false, 'Backup upload failed.');
				return;
			}

			try {
				response = JSON.parse(request.responseText);
			} catch (error) {
				finishModal(false, 'Backup upload failed.');
				return;
			}

			if (!response || !response.success || !response.data || !response.data.backup) {
				finishModal(false, response && response.data && response.data.message ? response.data.message : 'Backup upload failed.');
				return;
			}

			setUploadProgress(100, BackupFlowAdmin.strings.uploadComplete || 'Backup uploaded. Starting restore...');
			startRestore(response.data.backup.id, restoreMode, true);
		};

		request.open('POST', BackupFlowAdmin.ajaxUrl, true);
		request.send(formData);
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

		openModal('Backup in progress');
		post('backupflow_start_backup', {
			backup_type: type,
			destination: destination || 'local'
		}).done(function (response) {
			if (!response || !response.success || !response.data.job) {
				finishModal(false, 'Could not start backup.');
				return;
			}

			currentJobId = response.data.job.id;
			renderJob(response.data.job);
			processJob(currentJobId);
		}).fail(function (xhr) {
			var message = 'Could not start backup.';
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			finishModal(false, message);
		});
	}

	function startRestore(backupId, restoreMode, keepModal) {
		if (!keepModal) {
			openModal('Restore in progress');
		} else {
			$('.backupflow-modal').find('#backupflow-modal-title').text('Restore in progress');
		}
		post('backupflow_start_restore', {
			backup_id: backupId,
			restore_mode: restoreMode || 'full'
		}).done(function (response) {
			if (!response || !response.success || !response.data.job) {
				finishModal(false, 'Could not start restore.');
				return;
			}

			currentJobId = response.data.job.id;
			renderJob(response.data.job);
			processJob(currentJobId);
		}).fail(function (xhr) {
			var message = 'Could not start restore.';
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			finishModal(false, message);
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
