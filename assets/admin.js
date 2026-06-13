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

		var batchAction = currentJob.base_type === 'verify' ? 'media_flatten_run_verify_batch' : 'media_flatten_run_batch';
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
		request(type === 'verify' ? 'media_flatten_start_verify' : 'media_flatten_start_job', {
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
				['Old URLs in content', report.remaining.migrated_rows_old_url_in_post_content || 0],
				['Old URLs in excerpts', report.remaining.migrated_rows_old_url_in_post_excerpt || 0],
				['Old URLs in postmeta', report.remaining.migrated_rows_old_url_in_postmeta || 0],
				['Old URLs in options', report.remaining.migrated_rows_old_url_in_options || 0],
				['Last verification', verify.verified_at ? (verify.pass ? 'PASS' : 'FAIL') : 'Not run yet'],
				['Verification time', verify.verified_at || '-'],
				['Verify errors', verify.errors_count || 0],
				['Verify warnings', verify.warnings_count || 0],
				['Missing new files', verify.missing_new_files || 0],
				['Integrity mismatches', verify.integrity_mismatches || 0],
				['Metadata errors', verify.metadata_errors || 0],
				['Verify remaining old URLs', verify.old_url_occurrences ? Object.values(verify.old_url_occurrences).reduce(function (sum, count) { return sum + count; }, 0) : 0]
			];
			document.getElementById('mfm-status-cards').innerHTML = cards.map(function (card) {
				return '<div class="mfm-card"><strong>' + escapeHtml(String(card[1])) + '</strong><span>' + escapeHtml(card[0]) + '</span></div>';
			}).join('');
			clearLockButton.hidden = !report.lock_is_stale;
			renderVerify(report.verify);
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

	function refreshVerify() {
		request('media_flatten_get_verify_result').then(function (response) {
			renderVerify(response.result);
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
	document.querySelector('[data-mfm-refresh]').addEventListener('click', refreshReport);
	document.getElementById('mfm-refresh-verify').addEventListener('click', refreshVerify);
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
