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
		if (!dryRun && type !== 'verify' && !window.confirm(config.confirm)) {
			return;
		}

		stoppedLocally = false;
		var startAction = 'media_flatten_start_job';
		if (type === 'verify') {
			startAction = 'media_flatten_start_verify';
		} else if (type === 'old_url_audit') {
			startAction = 'media_flatten_start_old_url_audit';
		}
		request(startAction, {
			job_type: type,
			batch_size: type === 'install' ? 1 : batchSize(base)
		}).then(function (response) {
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
			var latestExports = redirectExport.exports || {};
			var latestPreview = redirectExport.latest_preview || {};
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
				['Redirect Export Ready', report.redirect_export_ready ? 'Yes' : 'No'],
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
			clearLockButton.hidden = !report.lock_is_stale;
			renderVerify(report.verify);
			renderAudit(report.old_url_audit);
			renderRedirect(report.redirect_export);
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
		if (!result || (!result.generated_at && !result.readiness)) {
			redirectStatus.className = 'mfm-verify-status';
			redirectStatus.textContent = 'Not run yet.';
			redirectResult.textContent = 'No redirect export preview stored.';
			return;
		}

		var ready = Boolean(result.ready || (result.readiness && result.readiness.ready));
		var ruleCount = result.redirect_rule_count ||
			result.export_rule_count ||
			(result.latest_preview ? result.latest_preview.redirect_rule_count : 0) ||
			(result.readiness ? result.readiness.redirect_rules_to_export : 0) ||
			0;
		var warnings = (result.warnings || []).length;
		var errors = (result.errors || []).length;
		redirectStatus.className = 'mfm-verify-status ' + (ready && !errors ? 'mfm-pass' : 'mfm-fail');
		redirectStatus.textContent = (ready && !errors ? 'READY' : 'NOT READY') + ' | rules ' + ruleCount +
			' | warnings ' + warnings + ' | errors ' + errors;
		redirectResult.textContent = JSON.stringify(result, null, 2);
	}

	function refreshRedirect() {
		request('media_flatten_get_report').then(function (report) {
			renderRedirect(report.redirect_export || {});
		}).catch(function (error) {
			showNotice(error.message, 'error');
		});
	}

	function runRedirectAction(format, preview) {
		var action = preview ? 'media_flatten_preview_redirects' : 'media_flatten_generate_redirect_export';
		var data = preview ? {} : { format: format };
		request(action, data).then(function (response) {
			var result = response.result || {};
			var ready = Boolean(result.ready || (result.readiness && result.readiness.ready));
			renderRedirect(response.result || {});
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
	if (document.getElementById('mfm-redirect-status')) {
		refreshRedirect();
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
