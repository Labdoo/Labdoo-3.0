(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.labdooTranslationJobs = {
    attach(context) {
      once('translation-job', '[data-translation-job]', context).forEach(element => {
        const initial = element.dataset.translationJob;
        if (!['queued', 'running', 'importing'].includes(initial)) return;
        let stopped = false;
        async function refresh() {
          if (stopped || !element.isConnected) return;
          try {
            const response = await fetch(element.dataset.statusUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error('Status unavailable');
            const job = await response.json();
            element.querySelector('[data-job-status]').textContent = job.label;
            element.querySelector('[data-job-counts]').textContent = job.counts;
            const progress = element.querySelector('[data-job-progress]');
            progress.max = Math.max(1, job.total);
            progress.value = job.completed;
            document.querySelectorAll('[data-language-status]').forEach(label => {
              const language = job.languages[label.dataset.languageStatus];
              if (language) label.textContent = language.label;
            });
            document.querySelectorAll('[data-language-progress]').forEach(label => {
              const language = job.languages[label.dataset.languageProgress];
              if (language) label.textContent = language.completed + ' / ' + language.total;
            });
            if (job.phase !== element.dataset.jobPhase || !['queued', 'running', 'importing'].includes(job.state)) {
              stopped = true;
              window.location.reload();
              return;
            }
          } catch (error) {
            element.querySelector('[data-job-status]').textContent = Drupal.t('Unable to refresh job status. Use the refresh link to try again.');
          }
          window.setTimeout(refresh, 3000);
        }
        window.setTimeout(refresh, 3000);
      });
    },
  };
})(Drupal, once);
