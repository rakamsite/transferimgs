(function () {
	'use strict';

	var config = window.MediaFlattenAdmin;
	var currentJob = null;
	var running = false;
	var stoppedLocally = false;
	var notice = document.getElementById('mfm-notice');
	var progressBar = document.getElementById('mfm-progress-bar');
	var progressText = document.getElementById('mfm-progress-text');
	var latest = document.getElementById('mfm-latest');
	var logs = document.getElementById('mfm-logs');
	var stopButton = document.getElementById('mfm-stop');
	var resumeButton = document.getElementById('mfm-resume');
	var clearLockButton = document.getElementById('mfm-clear-lock');
	var verifyStatus = document.getElementById('mfm-verify-status');
	var verifyResult = document.getElementById('mfm-verify-result');
	var auditStatus = document.getElementById('mfm-audit-status');
	var auditResult = document.getElementById('mfm-audit-result');
	var redirectStatus = document.getElementById('mfm-redirect-status');
	var redirectResult = document.getElementById('mfm-redirect-result');
	var deleteStatus = document.getElementById('mfm-delete-status');
	var deleteResult = document.getElementById('mfm-delete-result');
	var cleanupStatus = document.getElementById('mfm-cleanup-status');
	var cleanupResult = document.getElementById('mfm-cleanup-result');
	var finalStatus = document.getElementById('mfm-final-status');
	var finalResult = document.getElementById('mfm-final-result');
	var deleteConfirmCheck = document.getElementById('mfm-delete-confirm-check');
	var deleteConfirmPhrase = document.getElementById('mfm-delete-confirm-phrase');
	var deleteRunButton = document.getElementById('mfm-delete-run');
	var cleanupConfirmCheck = document.getElementById('mfm-cleanup-confirm-check');
	var cleanupConfirmPhrase = document.getElementById('mfm-cleanup-confirm-phrase');
	var cleanupRunButton = document.getElementById('mfm-cleanup-run');

	function request(action, data) {
		var body = new URLSearchParams(Object.assign({ action: action, nonce: config.nonce }, data || {}));
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (!response.success) {
				throw new Error(response.data && response.data.message ? response.data.message : 'The server returned an error.');
			}
			return response.data;
		});
	}

	function showNotice(message, type) {
		notice.hidden = false;
		notice.className = 'mfm-notice mfm-' + (type || 'info');
		notice.textContent = message;
	}

	function batchSize(type) {
		var input = document.querySelector('[data-mfm-batch="' + type + '"]');
		return input ? parseInt(input.value, 10) : 1;
	}

	function renderJob(job) {
		currentJob = job || null;
		if (!job) {
			progressBar.style.width = '0%';
			progressText.textContent = 'No job running.';
			logs.textContent = 'No logs yet.';
			resumeButton.hidden = true;
			return;
		}

		var total = Math.max(1, parseInt(job.total_estimated, 10) || 1);
		var processed = parseInt(job.processed_items, 10) || 0;
		var percent = job.status === 'complete' ? 100 : Math.min(99, Math.round((processed / total) * 100));
		progressBar.style.width = percent + '%';
		progressText.textContent = job.job_type + ': ' + job.status + ' | processed ' + processed + ' / estimated ' + total +
			' | success ' + job.success_count + ' | skipped ' + job.skipped_count + ' | failed ' + job.failed_count;
		logs.textContent = (job.logs || []).join('\n') || 'No logs yet.';
		logs.scrollTop = logs.scrollHeight;
		resumeButton.hidden = Boolean(job.dry_run) || (job.status !== 'paused' && job.status !== 'running');
	}

	function runLoop() {
		if (!currentJob || stoppedLocally || currentJob.status !== 'running') {
			running = false;
			stopButton.disabled = true;
			return;
		}

		running = true;
		stopButton.disabled = false;
		var data = {};
		if (currentJob.dry_run) {
			data.dry_job = JSON.stringify(currentJob);
		}

		var batchAction = 'media_flatten_run_batch';
		if (currentJob.base_type === 'verify') {
			batchAction = 'media_flatten_run_verify_batch';
		} else if (currentJob.base_type === 'old_url_audit') {
			batchAction = 'media_flatten_run_old_url_audit_batch';
		} else if (currentJob.base_type === 'delete_old_files') {
			batchAction = 'media_flatten_delete_old_files_batch';
		} else if (currentJob.base_type === 'cleanup_empty_dirs') {
			batchAction = 'media_flatten_cleanup_empty_dirs_batch';
		}
		request(batchAction, data).then(function (response) {
			renderJob(response.job);
			latest.textContent = JSON.stringify(response.latest, null, 2);
			if (response.done) {
				running = false;
				stopButton.disabled = true;
				showNotice('Job complete.', 'success');
				refreshReport();
				return;
			}
			window.setTimeout(runLoop, 150);
		}).catch(function (error) {
			running = false;
			stopButton.disabled = true;
			showNotice(error.message, 'error');
		});
	}

	function startJob(type) {
		var base = type.replace(/_dry_run$/, '');
		var dryRun = /_dry_run$/.test(type);
		if (!dryRun && base === 'delete_old_files') {
			var deleteConfirmChecked = deleteConfirmCheck && deleteConfirmCheck.checked;
			var deleteConfirmText = deleteConfirmPhrase ? deleteConfirmPhrase.value.trim() : '';
			if (!deleteConfirmChecked || deleteConfirmText !== 'DELETE OLD FILES') {
				showNotice('Check the backup box and type DELETE OLD FILES before starting deletion.', 'error');
				return;
			}
		} else if (!dryRun && base === 'cleanup_empty_dirs') {
			var cleanupConfirmChecked = cleanupConfirmCheck && cleanupConfirmCheck.checked;
			var cleanupConfirmText = cleanupConfirmPhrase ? cleanupConfirmPhrase.value.trim() : '';
			if (!cleanupConfirmChecked || cleanupConfirmText !== 'CLEANUP EMPTY DIRECTORIES') {
				showNotice('Check the box and type CLEANUP EMPTY DIRECTORIES before starting cleanup.', 'error');
				return;
			}
		} else if (!dryRun && type !== 'verify' && !window.confirm(config.confirm)) {
			return;
		}

		stoppedLocally = false;
		var startAction = 'media_flatten_start_job';
		if (type === 'verify') {
			startAction = 'media_flatten_start_verify';
		} else if (type === 'old_url_audit') {
			startAction = 'media_flatten_start_old_url_audit';
		} else if (type === 'delete_old_files' || type === 'delete_old_files_dry_run') {
			startAction = dryRun ? 'media_flatten_delete_old_files_dry_run' : 'media_flatten_start_delete_old_files';
		} else if (type === 'cleanup_empty_dirs' || type === 'cleanup_empty_dirs_dry_run') {
			startAction = dryRun ? 'media_flatten_cleanup_empty_dirs_dry_run' : 'media_flatten_start_cleanup_empty_dirs';
		}
		var payload = {
			job_type: type,
			batch_size: type === 'install' ? 1 : batchSize(base)
		};
		if (base === 'delete_old_files' && !dryRun) {
			payload.delete_confirm_checked = deleteConfirmCheck && deleteConfirmCheck.checked ? 1 : 0;
			payload.delete_confirm_phrase = deleteConfirmPhrase ? deleteConfirmPhrase.value.trim() : '';
		}
		if (base === 'cleanup_empty_dirs' && !dryRun) {
			payload.cleanup_confirm_checked = cleanupConfirmCheck && cleanupConfirmCheck.checked ? 1 : 0;
			payload.cleanup_confirm_phrase = cleanupConfirmPhrase ? cleanupConfirmPhrase.value.trim() : '';
		}
		request(startAction, payload).then(function (response) {
			renderJob(response.job);
			showNotice(response.resumed ? 'Job resumed.' : 'Job started.', 'info');
			runLoop();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function stopJob() {
		stoppedLocally = true;
		stopButton.disabled = true;
		if (currentJob && currentJob.dry_run) {
			currentJob.status = 'paused';
			renderJob(currentJob);
			showNotice('Dry run paused in this browser. Start it again to restart the preview.', 'warning');
			return;
		}
		request('media_flatten_stop_job').then(function (response) {
			renderJob(response.job);
			showNotice('Job paused safely.', 'warning');
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function refreshReport() {
		request('media_flatten_get_report').then(function (report) {
			var verify = report.verify || {};
			var audit = report.old_url_audit || {};
			var redirectExport = report.redirect_export || {};
			var deleteReport = report.delete_old_files || {};
			var deleteReadiness = report.delete_readiness || {};
			var cleanupReport = report.cleanup_result || {};
			var cleanupReadiness = report.cleanup_readiness || {};
			var finalReport = report.final_report || {};
			var redirectPreviewStatus = report.redirect_preview_status || {};
			var redirectExportStatus = report.redirect_export_status || {};
			var deleteReady = Boolean(report.delete_old_files_ready || deleteReadiness.delete_old_files_ready || deleteReadiness.ready);
			var cleanupReady = Boolean(report.cleanup_ready || cleanupReadiness.cleanup_ready || cleanupReadiness.ready);
			var latestExports = redirectExport.exports || {};
			var latestPreview = redirectExport.preview || redirectExport.latest_preview || {};
			var extensionCounts = latestPreview.extension_counts || {};
			var cards = [
				['Manifest table', report.table_exists ? 'Installed' : 'Missing'],
				['Total manifest rows', report.total_rows],
				['Pending', report.statuses.pending || 0],
				['Missing', report.statuses.missing || 0],
				['Blocked collision', report.statuses.blocked_collision || 0],
				['Resolved', report.statuses.resolved || 0],
				['Migrated', report.statuses.migrated || 0],
				['Failed', report.statuses.failed || 0],
				['WebP', report.extensions.webp || 0],
				['PNG', report.extensions.png || 0],
				['JPG/JPEG', report.extensions['jpg/jpeg'] || 0],
				['Non-ASCII filenames', report.non_ascii || 0],
				['Last verification', verify.verified_at ? (verify.pass ? 'PASS' : 'FAIL') : 'Not run yet'],
				['Verification time', verify.verified_at || '-'],
				['Verify errors', verify.errors_count || 0],
				['Verify warnings', verify.warnings_count || 0],
				['Missing new files', verify.missing_new_files || 0],
				['Integrity mismatches', verify.integrity_mismatches || 0],
				['Metadata errors', verify.metadata_errors || 0],
				['Verify remaining old URLs', (verify.migrated_mapping_old_url_remaining || 0) + (verify.non_migrated_manifest_url_remaining || 0) + (verify.orphan_old_upload_url_remaining || 0)],
				['Old URL audit', audit.audited_at ? (audit.safe ? 'Safe' : 'Unsafe') : 'Not run yet'],
				['Migrated old URLs', audit.migrated_mapping_old_url_remaining || 0],
				['Non-migrated URLs', audit.non_migrated_manifest_url_remaining || 0],
				['Orphan dated URLs', audit.orphan_old_upload_url_remaining || 0],
				['Dated URL occurrences', audit.generic_dated_upload_occurrences || 0],
				['Redirect preview status', redirectPreviewStatus.label || (redirectPreviewStatus.has_run ? (redirectPreviewStatus.ready ? 'PASS' : 'FAIL') : 'Not run yet')],
				['Redirect preview ready', report.redirect_preview_ready ? 'Yes' : 'No'],
				['Final redirect export status', redirectExportStatus.label || (redirectExportStatus.has_run ? (redirectExportStatus.ready ? 'PASS' : 'FAIL') : 'Not run yet')],
				['Final redirect export ready', report.redirect_export_ready ? 'Yes' : 'No'],
				['Delete Old Files Ready', deleteReady ? 'Yes' : 'No'],
				['Delete dry-run status', (deleteReadiness.dry_run_status || deleteReport.dry_run_status || 'not_run').replace(/_/g, ' ').toUpperCase()],
				['Last successful dry-run', deleteReadiness.dry_run_completed_at || deleteReport.dry_run_completed_at || '-'],
				['Final redirect export has run', deleteReadiness.final_redirect_export_has_run ? 'Yes' : 'No'],
				['Latest successful export format', deleteReadiness.final_redirect_export_format || '-'],
				['Latest successful export file', deleteReadiness.final_redirect_export_file || '-'],
				['Delete eligible', deleteReport.eligible_count || 0],
				['Delete deleted', deleteReport.deleted_count || 0],
				['Delete already missing', deleteReport.already_missing_count || 0],
				['Delete failures', deleteReport.failed_count || 0],
				['Delete bytes eligible', deleteReport.bytes_eligible || 0],
				['Delete bytes freed', deleteReport.bytes_freed || 0],
				['Latest delete batch', deleteReport.last_batch_at || '-'],
				['Latest deletion errors', (deleteReport.errors || deleteReport.latest_errors || []).length || 0],
				['Cleanup Ready', cleanupReady ? 'Yes' : 'No'],
				['Cleanup dry-run status', (cleanupReadiness.dry_run_status || cleanupReport.dry_run_status || 'not_run').replace(/_/g, ' ').toUpperCase()],
				['Last cleanup dry-run', cleanupReadiness.dry_run_completed_at || cleanupReport.dry_run_completed_at || '-'],
				['Empty month dirs', cleanupReport.empty_month_dirs_found || 0],
				['Empty year dirs', cleanupReport.empty_year_dirs_found || 0],
				['Directories removed', cleanupReport.removed_count || 0],
				['Directories remaining', cleanupReadiness.remaining_old_directories || cleanupReport.remaining_count || 0],
				['Latest cleanup time', cleanupReport.last_cleanup_at || cleanupReport.completed_at || '-'],
				['Final Migration Status', finalReport.generated_at ? (finalReport.status || (finalReport.pass ? 'PASS' : 'NOT READY')) : 'Not run yet'],
				['Final report time', finalReport.generated_at || '-'],
				['Final report old files remaining', finalReport.old_files_remaining || 0],
				['Final report directories remaining', finalReport.old_yyyy_mm_directories_remaining || 0],
				['Final report unsafe dirs', finalReport.unsafe_directories_remaining || 0],
				['Final report cleanup failures', finalReport.cleanup_failures || 0],
				['Migrated mappings available', latestPreview.total_migrated_mappings || 0],
				['Redirect Rules', latestPreview.redirect_rule_count || 0],
				['Persian / non-ASCII mappings', latestPreview.unicode_filename_count || 0],
				['Redirect WebP', extensionCounts.webp || 0],
				['Redirect PNG', extensionCounts.png || 0],
				['Redirect JPG/JPEG', extensionCounts['jpg/jpeg'] || 0],
				['Redirect GIF', extensionCounts.gif || 0],
				['Redirect SVG', extensionCounts.svg || 0],
				['Redirect PDF', extensionCounts.pdf || 0],
				['Redirect Other', extensionCounts.other || 0],
				['Latest Apache File', latestExports.apache && latestExports.apache.file_name ? latestExports.apache.file_name : '-'],
				['Latest Nginx File', latestExports.nginx && latestExports.nginx.file_name ? latestExports.nginx.file_name : '-'],
				['Latest CSV File', latestExports.csv && latestExports.csv.file_name ? latestExports.csv.file_name : '-'],
				['Export Warnings', redirectExport.warnings ? redirectExport.warnings.length : 0],
				['Export Errors', redirectExport.errors ? redirectExport.errors.length : 0]
			];
			document.getElementById('mfm-status-cards').innerHTML = cards.map(function (card) {
				return '<div class="mfm-card"><strong>' + escapeHtml(String(card[1])) + '</strong><span>' + escapeHtml(card[0]) + '</span></div>';
			}).join('');
			document.querySelectorAll('[data-mfm-download-format]').forEach(function (button) {
				var format = button.getAttribute('data-mfm-download-format');
				var href = button.getAttribute('data-mfm-download-href') || '#';
				var latest = latestExports[format] || {};
				var available = Boolean(latest && latest.file_name);
				if (available) {
					button.href = href;
					button.removeAttribute('aria-disabled');
					button.classList.remove('disabled');
					button.style.pointerEvents = '';
					button.style.opacity = '';
					return;
				}
				button.href = '#';
				button.setAttribute('aria-disabled', 'true');
				button.classList.add('disabled');
				button.style.pointerEvents = 'none';
				button.style.opacity = '0.5';
			});
			if (deleteRunButton) {
				var deleteConfirmOk = Boolean(deleteConfirmCheck && deleteConfirmCheck.checked && deleteConfirmPhrase && deleteConfirmPhrase.value.trim() === 'DELETE OLD FILES');
				var dryRunOk = (deleteReadiness.dry_run_status || deleteReport.dry_run_status || '') === 'pass';
				deleteRunButton.disabled = !(deleteReady && deleteConfirmOk && dryRunOk);
			}
			if (cleanupRunButton) {
				var cleanupConfirmOk = Boolean(cleanupConfirmCheck && cleanupConfirmCheck.checked && cleanupConfirmPhrase && cleanupConfirmPhrase.value.trim() === 'CLEANUP EMPTY DIRECTORIES');
				cleanupRunButton.disabled = !(cleanupReady && cleanupConfirmOk);
			}
			clearLockButton.hidden = !report.lock_is_stale;
			renderVerify(report.verify);
			renderAudit(report.old_url_audit);
			renderRedirect(report);
			renderDelete(deleteReport);
			renderCleanup(Object.assign({}, cleanupReport, cleanupReadiness));
			renderFinalReport(finalReport);
			if (report.job && report.job.job_type && !running && (!currentJob || !currentJob.dry_run)) {
				renderJob(report.job);
			}
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function renderVerify(result) {
		if (!result || !result.verified_at) {
			verifyStatus.className = 'mfm-verify-status';
			verifyStatus.textContent = 'Not run yet.';
			verifyResult.textContent = 'No verification result stored.';
			return;
		}
		verifyStatus.className = 'mfm-verify-status ' + (result.pass ? 'mfm-pass' : 'mfm-fail');
		verifyStatus.textContent = (result.pass ? 'PASS' : 'FAIL') + ' | ' + result.verified_at +
			' | errors ' + result.errors_count + ' | warnings ' + result.warnings_count;
		verifyResult.textContent = JSON.stringify(result, null, 2);
	}

	function renderAudit(result) {
		if (!result || !result.audited_at) {
			auditStatus.className = 'mfm-verify-status';
			auditStatus.textContent = 'Not run yet.';
			auditResult.textContent = 'No old URL audit result stored.';
			return;
		}
		auditStatus.className = 'mfm-verify-status ' + (result.safe ? 'mfm-pass' : 'mfm-fail');
		auditStatus.textContent = (result.safe ? 'SAFE' : 'UNSAFE') + ' | ' + result.audited_at +
			' | migrated ' + result.migrated_mapping_old_url_remaining +
			' | non-migrated ' + result.non_migrated_manifest_url_remaining +
			' | orphan ' + result.orphan_old_upload_url_remaining;
		auditResult.textContent = JSON.stringify(result, null, 2);
	}

	function refreshVerify() {
		request('media_flatten_get_verify_result').then(function (response) {
			renderVerify(response.result);
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function refreshAudit() {
		request('media_flatten_get_old_url_audit_result').then(function (response) {
			renderAudit(response.result);
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function renderRedirect(result) {
		if (!result || (!result.generated_at && !result.readiness && !result.redirect_preview_status && !result.redirect_export_status)) {
			redirectStatus.className = 'mfm-verify-status';
			redirectStatus.textContent = 'Not run yet.';
			redirectResult.textContent = 'No redirect export preview stored.';
			return;
		}

		var previewStatus = result.redirect_preview_status || (result.readiness && result.readiness.redirect_preview_status) || {};
		var exportStatus = result.redirect_export_status || (result.readiness && result.readiness.redirect_export_status) || {};
		var hasStructuredStatuses = Boolean(result.redirect_preview_status || result.redirect_export_status || (result.readiness && (result.readiness.redirect_preview_status || result.readiness.redirect_export_status)));
		var ready = Boolean(result.ready || (result.readiness && result.readiness.ready) || result.redirect_export_ready);
		var ruleCount = result.redirect_rule_count ||
			result.export_rule_count ||
			(result.latest_preview ? result.latest_preview.redirect_rule_count : 0) ||
			(result.readiness ? result.readiness.redirect_rules_to_export : 0) ||
			(previewStatus.redirect_rule_count || 0) ||
			0;
		var warnings = (result.warnings || previewStatus.warnings || exportStatus.warnings || []).length;
		var errors = (result.errors || previewStatus.errors || exportStatus.errors || []).length;
		if (hasStructuredStatuses) {
			var previewLabel = previewStatus.label || (previewStatus.has_run ? (previewStatus.ready ? 'Preview passed.' : 'Preview failed.') : 'Preview not run yet.');
			var exportLabel = exportStatus.label || (exportStatus.has_run ? (exportStatus.ready ? 'Final redirect export passed.' : 'Final redirect export failed.') : 'Final redirect export not run yet.');
			redirectStatus.className = 'mfm-verify-status ' + ((ready && !errors) ? 'mfm-pass' : 'mfm-fail');
			redirectStatus.textContent = 'Preview: ' + previewLabel + ' | Export: ' + exportLabel +
				' | rules ' + ruleCount + ' | warnings ' + warnings + ' | errors ' + errors;
		} else {
			redirectStatus.className = 'mfm-verify-status ' + (ready && !errors ? 'mfm-pass' : 'mfm-fail');
			redirectStatus.textContent = (ready && !errors ? 'READY' : 'NOT READY') + ' | rules ' + ruleCount +
				' | warnings ' + warnings + ' | errors ' + errors;
		}
		redirectResult.textContent = JSON.stringify(result, null, 2);
	}

	function renderDelete(result) {
		if (!result || (!result.generated_at && !result.completed_at && !result.last_batch_at)) {
			deleteStatus.className = 'mfm-verify-status';
			deleteStatus.textContent = 'Not run yet.';
			deleteResult.textContent = 'No deletion report stored.';
			return;
		}

		var ready = Boolean(result.delete_old_files_ready);
		var dryRunStatus = (result.dry_run_status || (result.dry_run_pass ? 'pass' : 'not_run')).replace(/_/g, ' ').toUpperCase();
		deleteStatus.className = 'mfm-verify-status ' + (ready ? 'mfm-pass' : 'mfm-fail');
		deleteStatus.textContent = (ready ? 'READY' : 'NOT READY') + ' | eligible ' + (result.eligible_count || 0) +
			' | deleted ' + (result.deleted_count || 0) +
			' | missing ' + (result.already_missing_count || 0) +
			' | failed ' + (result.failed_count || 0) +
			' | bytes freed ' + (result.bytes_freed || 0) +
			' | dry-run ' + dryRunStatus +
			' | export ' + (result.final_redirect_export_has_run ? 'YES' : 'NO');
		deleteResult.textContent = JSON.stringify(result, null, 2);
	}

	function renderCleanup(result) {
		if (!result || (!result.generated_at && !result.completed_at && !result.last_batch_at && !result.dry_run_completed_at)) {
			cleanupStatus.className = 'mfm-verify-status';
			cleanupStatus.textContent = 'Not run yet.';
			cleanupResult.textContent = 'No cleanup report stored.';
			return;
		}

		var ready = Boolean(result.cleanup_ready);
		var dryRunPassed = Boolean(result.dry_run_pass);
		var dryRunStatus = (result.dry_run_status || (dryRunPassed ? 'pass' : 'not_run')).replace(/_/g, ' ').toUpperCase();
		cleanupStatus.className = 'mfm-verify-status ' + (ready ? 'mfm-pass' : (dryRunPassed ? 'mfm-warning' : 'mfm-fail'));
		cleanupStatus.textContent = (ready ? 'READY' : (dryRunPassed ? 'DRY RUN PASS' : 'NOT READY')) + ' | remaining ' + (result.remaining_old_directories || result.remaining_count || 0) +
			' | removed ' + (result.removed_count || 0) +
			' | empty month ' + (result.empty_month_dirs_found || 0) +
			' | empty year ' + (result.empty_year_dirs_found || 0) +
			' | not empty ' + (result.skipped_not_empty_count || 0) +
			' | unsafe ' + (result.skipped_unsafe_count || 0) +
			' | dry-run ' + dryRunStatus;
		cleanupResult.textContent = JSON.stringify(result, null, 2);
	}

	function renderFinalReport(result) {
		if (!result || (!result.generated_at && !result.verified_at && !result.audited_at)) {
			finalStatus.className = 'mfm-verify-status';
			finalStatus.textContent = 'Not run yet.';
			finalResult.textContent = 'No final migration report stored.';
			return;
		}

		var pass = Boolean(result.pass);
		finalStatus.className = 'mfm-verify-status ' + (pass ? 'mfm-pass' : 'mfm-fail');
		finalStatus.textContent = (pass ? 'PASS' : 'NOT READY') + ' | manifest ' + (result.total_manifest_rows || 0) +
			' | migrated ' + (result.total_migrated_rows || 0) +
			' | old files remaining ' + (result.old_files_remaining || 0) +
			' | dirs remaining ' + (result.old_yyyy_mm_directories_remaining || 0) +
			' | unsafe dirs ' + (result.unsafe_directories_remaining || 0) +
			' | old URLs ' + (result.remaining_old_url_occurrences || 0);
		finalResult.textContent = JSON.stringify(result, null, 2);
	}

	function refreshDelete() {
		request('media_flatten_get_delete_report').then(function (response) {
			var result = Object.assign({}, response.result || {}, response.readiness || {});
			renderDelete(result);
			refreshReport();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function refreshCleanup() {
		request('media_flatten_get_cleanup_report').then(function (response) {
			var result = Object.assign({}, response.result || {}, response.readiness || {});
			renderCleanup(result);
			refreshReport();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function refreshFinalReport() {
		request('media_flatten_get_final_report').then(function (response) {
			var result = response.result || response.state || {};
			renderFinalReport(result);
			refreshReport();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function refreshRedirect() {
		request('media_flatten_get_report').then(function (report) {
			renderRedirect(report);
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function runRedirectAction(format, preview) {
		var action = preview ? 'media_flatten_preview_redirects' : 'media_flatten_generate_redirect_export';
		var data = preview ? {} : { format: format };
		request(action, data).then(function (response) {
			var result = response.readiness || response.result || {};
			var ready = Boolean(result.ready || (result.readiness && result.readiness.ready));
			renderRedirect(result);
			showNotice(preview ? 'Redirect preview complete.' : 'Redirect export generated.', ready ? 'success' : 'warning');
			refreshReport();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = value;
		return div.innerHTML;
	}

	document.querySelectorAll('[data-mfm-action]').forEach(function (button) {
		button.addEventListener('click', function () {
			startJob(button.getAttribute('data-mfm-action'));
		});
	});
	document.querySelectorAll('[data-mfm-redirect-action]').forEach(function (button) {
		button.addEventListener('click', function () {
			var value = button.getAttribute('data-mfm-redirect-action');
			if (value === 'preview') {
				runRedirectAction('', true);
				return;
			}
			runRedirectAction(value, false);
		});
	});
	document.querySelector('[data-mfm-refresh]').addEventListener('click', refreshReport);
	document.getElementById('mfm-refresh-verify').addEventListener('click', refreshVerify);
	document.getElementById('mfm-refresh-audit').addEventListener('click', refreshAudit);
	if (document.getElementById('mfm-refresh-delete')) {
		document.getElementById('mfm-refresh-delete').addEventListener('click', refreshDelete);
	}
	if (document.getElementById('mfm-refresh-cleanup')) {
		document.getElementById('mfm-refresh-cleanup').addEventListener('click', refreshCleanup);
	}
	if (document.getElementById('mfm-refresh-final')) {
		document.getElementById('mfm-refresh-final').addEventListener('click', refreshFinalReport);
	}
	if (document.getElementById('mfm-redirect-status')) {
		refreshRedirect();
	}
	if (deleteConfirmCheck) {
		deleteConfirmCheck.addEventListener('change', refreshReport);
	}
	if (deleteConfirmPhrase) {
		deleteConfirmPhrase.addEventListener('input', refreshReport);
	}
	if (cleanupConfirmCheck) {
		cleanupConfirmCheck.addEventListener('change', refreshReport);
	}
	if (cleanupConfirmPhrase) {
		cleanupConfirmPhrase.addEventListener('input', refreshReport);
	}
	stopButton.addEventListener('click', stopJob);
	resumeButton.addEventListener('click', function () {
		if (currentJob && !currentJob.dry_run) {
			startJob(currentJob.job_type);
		}
	});
	clearLockButton.addEventListener('click', function () {
		if (!window.confirm('Clear the stale job lock? Only do this when no migration request is still running.')) {
			return;
		}
		request('media_flatten_clear_stale_lock').then(function () {
			showNotice('Stale lock cleared.', 'success');
			refreshReport();
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	});

	refreshReport();
}());
