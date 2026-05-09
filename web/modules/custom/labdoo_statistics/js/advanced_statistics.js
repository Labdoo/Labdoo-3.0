(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.labdooAdvancedStatistics = {
    attach: function (context, settings) {
      if (!settings.labdoo_statistics) {
        return;
      }

      const stats = settings.labdoo_statistics;

      // 1. Dootronics Evolution
      const ctxEvolution = once('labdoo-evolution', '#evolutionChart', context);
      if (ctxEvolution.length) {
        new Chart(ctxEvolution[0], {
          type: 'line',
          data: {
            labels: stats.evolution_data.labels,
            datasets: [{
              label: Drupal.t('Dootronics registered'),
              data: stats.evolution_data.values,
              borderColor: 'rgb(75, 192, 192)',
              tension: 0.1,
              fill: false
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 2. Dootronics Status
      const ctxStatus = once('labdoo-status', '#statusChart', context);
      if (ctxStatus.length) {
        new Chart(ctxStatus[0], {
          type: 'doughnut',
          data: {
            labels: stats.status_data.labels,
            datasets: [{
              data: stats.status_data.values,
              backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 3. Dootronic Contributors
      const ctxDootronicContributors = once('labdoo-dootronic-contributors', '#dootronicContributorsChart', context);
      if (ctxDootronicContributors.length) {
        new Chart(ctxDootronicContributors[0], {
          type: 'bar',
          data: {
            labels: stats.top_dootronic_contributors.labels,
            datasets: [{
              label: Drupal.t('Dootronics contributed'),
              data: stats.top_dootronic_contributors.values,
              backgroundColor: 'rgba(54, 162, 235, 0.5)',
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 3a. Dootronic Device Type
      const ctxDootronicDevice = once('labdoo-dootronic-device', '#dootronicDeviceChart', context);
      if (ctxDootronicDevice.length) {
        new Chart(ctxDootronicDevice[0], {
          type: 'pie',
          data: {
            labels: stats.dootronic_device_data.labels,
            datasets: [{
              data: stats.dootronic_device_data.values,
              backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 3b. Dootronic CPU
      const ctxDootronicCpu = once('labdoo-dootronic-cpu', '#dootronicCpuChart', context);
      if (ctxDootronicCpu.length) {
        new Chart(ctxDootronicCpu[0], {
          type: 'doughnut',
          data: {
            labels: stats.dootronic_cpu_data.labels,
            datasets: [{
              data: stats.dootronic_cpu_data.values,
              backgroundColor: ['#4BC0C0', '#FF9F40', '#9966FF', '#FF6384', '#C9CBCF', '#36A2EB']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 4. Edoovillage Evolution
      const ctxEdoovillageEvolution = once('labdoo-edoovillage-evolution', '#edoovillageEvolutionChart', context);
      if (ctxEdoovillageEvolution.length) {
        new Chart(ctxEdoovillageEvolution[0], {
          type: 'line',
          data: {
            labels: stats.edoovillage_evolution.labels,
            datasets: [{
              label: Drupal.t('Schools registered'),
              data: stats.edoovillage_evolution.values,
              borderColor: 'rgb(153, 102, 255)',
              tension: 0.1,
              fill: false
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 5. Country Chart (Students)
      const ctxCountry = once('labdoo-country', '#countryChart', context);
      if (ctxCountry.length) {
        new Chart(ctxCountry[0], {
          type: 'bar',
          data: {
            labels: stats.country_data.labels,
            datasets: [{
              label: Drupal.t('Students reached'),
              data: stats.country_data.values,
              backgroundColor: 'rgba(255, 159, 64, 0.5)',
              borderColor: 'rgb(255, 159, 64)',
              borderWidth: 1
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false
          }
        });
      }

      // 6. Hub Status
      const ctxHubStatus = once('labdoo-hub-status', '#hubStatusChart', context);
      if (ctxHubStatus.length) {
        new Chart(ctxHubStatus[0], {
          type: 'pie',
          data: {
            labels: stats.hub_status_data.labels,
            datasets: [{
              data: stats.hub_status_data.values,
              backgroundColor: ['#4BC0C0', '#FF9F40', '#9966FF', '#FF6384', '#C9CBCF']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 6.1 Hubs by Country
      const ctxHubCountry = once('labdoo-hub-country', '#hubCountryChart', context);
      if (ctxHubCountry.length) {
        new Chart(ctxHubCountry[0], {
          type: 'doughnut',
          data: {
            labels: stats.hubs_by_country.labels,
            datasets: [{
              data: stats.hubs_by_country.values,
              backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                '#FF9F40', '#E7E9ED', '#4D5360', '#C9CBCF', '#7BC225'
              ]
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 6.2 Hub Evolution
      const ctxHubEvolution = once('labdoo-hub-evolution', '#hubEvolutionChart', context);
      if (ctxHubEvolution.length) {
        new Chart(ctxHubEvolution[0], {
          type: 'line',
          data: {
            labels: stats.hub_evolution.labels,
            datasets: [{
              label: Drupal.t('Hubs registered'),
              data: stats.hub_evolution.values,
              borderColor: 'rgb(255, 159, 64)',
              tension: 0.1,
              fill: true,
              backgroundColor: 'rgba(255, 159, 64, 0.2)'
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 6.3 Hub Activity
      const ctxHubActivity = once('labdoo-hub-activity', '#hubActivityChart', context);
      if (ctxHubActivity.length) {
        new Chart(ctxHubActivity[0], {
          type: 'bar',
          data: {
            labels: stats.top_hubs_activity.labels,
            datasets: [{
              label: Drupal.t('Dootronics processed'),
              data: stats.top_hubs_activity.values,
              backgroundColor: 'rgba(153, 102, 255, 0.5)',
              borderColor: 'rgb(153, 102, 255)',
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 7. Dootrip Km Evolution
      const ctxDootripKm = once('labdoo-dootrip-km', '#dootripKmEvolutionChart', context);
      if (ctxDootripKm.length) {
        new Chart(ctxDootripKm[0], {
          type: 'bar',
          data: {
            labels: stats.dootrip_km_data.evolution.labels,
            datasets: [{
              label: Drupal.t('Kilometers'),
              data: stats.dootrip_km_data.evolution.values,
              backgroundColor: 'rgba(75, 192, 192, 0.5)',
              borderColor: 'rgb(75, 192, 192)',
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 8. Tasks Distribution by Team
      const ctxTasksTeam = once('labdoo-tasks-team', '#tasksByTeamChart', context);
      if (ctxTasksTeam.length) {
        new Chart(ctxTasksTeam[0], {
          type: 'doughnut',
          data: {
            labels: stats.tasks_by_team_data.labels,
            datasets: [{
              data: stats.tasks_by_team_data.values,
              backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                '#FF9F40', '#C9CBCF', '#4BC0C0', '#FF6384', '#36A2EB'
              ]
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 9. Team Activity
      const ctxTeamActivity = once('labdoo-team-activity', '#teamActivityChart', context);
      if (ctxTeamActivity.length) {
        new Chart(ctxTeamActivity[0], {
          type: 'bar',
          data: {
            labels: stats.team_activity_data.labels,
            datasets: [{
              label: Drupal.t('Tasks'),
              data: stats.team_activity_data.values,
              backgroundColor: 'rgba(54, 162, 235, 0.5)',
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 10. Task Type
      const ctxTaskType = once('labdoo-task-type', '#taskTypeChart', context);
      if (ctxTaskType.length) {
        new Chart(ctxTaskType[0], {
          type: 'pie',
          data: {
            labels: stats.task_type_data.labels,
            datasets: [{
              data: stats.task_type_data.values,
              backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 11. Task Priority
      const ctxPriority = once('labdoo-priority', '#priorityChart', context);
      if (ctxPriority.length) {
        new Chart(ctxPriority[0], {
          type: 'bar',
          data: {
            labels: stats.priority_data.labels,
            datasets: [{
              label: Drupal.t('Tasks count'),
              data: stats.priority_data.values,
              backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 12. Task Status
      const ctxTaskStatus = once('labdoo-task-status', '#taskStatusChart', context);
      if (ctxTaskStatus.length) {
        new Chart(ctxTaskStatus[0], {
          type: 'doughnut',
          data: {
            labels: stats.task_status_data.labels,
            datasets: [{
              data: stats.task_status_data.values,
              backgroundColor: ['#4BC0C0', '#FF9F40', '#9966FF', '#FF6384', '#C9CBCF', '#36A2EB']
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 13. Comment Evolution
      const ctxCommentEvolution = once('labdoo-comment-evolution', '#commentEvolutionChart', context);
      if (ctxCommentEvolution.length) {
        new Chart(ctxCommentEvolution[0], {
          type: 'line',
          data: {
            labels: stats.comment_evolution_data.labels,
            datasets: [{
              label: Drupal.t('Comments posted'),
              data: stats.comment_evolution_data.values,
              borderColor: 'rgb(255, 99, 132)',
              tension: 0.1,
              fill: false
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 14. Top Commenters
      const ctxCommenters = once('labdoo-commenters', '#commentersChart', context);
      if (ctxCommenters.length) {
        new Chart(ctxCommenters[0], {
          type: 'bar',
          data: {
            labels: stats.top_commenters.labels,
            datasets: [{
              label: Drupal.t('Comments'),
              data: stats.top_commenters.values,
              backgroundColor: 'rgba(153, 102, 255, 0.5)',
              borderColor: 'rgb(153, 102, 255)',
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 15. Laptops per School
      const ctxLaptopsEdoovillage = once('labdoo-laptops-edoovillage', '#laptopsPerEdoovillageChart', context);
      if (ctxLaptopsEdoovillage.length) {
        new Chart(ctxLaptopsEdoovillage[0], {
          type: 'bar',
          data: {
            labels: stats.laptops_per_edoovillage.labels,
            datasets: [{
              label: Drupal.t('Laptops delivered'),
              data: stats.laptops_per_edoovillage.values,
              backgroundColor: 'rgba(54, 162, 235, 0.5)',
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false
          }
        });
      }

      // 16. Laptops per Student Ratio
      const ctxLaptopsStudent = once('labdoo-laptops-student', '#laptopsPerStudentChart', context);
      if (ctxLaptopsStudent.length) {
        new Chart(ctxLaptopsStudent[0], {
          type: 'bar',
          data: {
            labels: stats.laptops_per_student.labels,
            datasets: [{
              label: Drupal.t('Laptops per student'),
              data: stats.laptops_per_student.values,
              backgroundColor: 'rgba(75, 192, 192, 0.5)',
              borderColor: 'rgb(75, 192, 192)',
              borderWidth: 1
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false
          }
        });
      }

      // 17. Node Evolution
      const ctxNodeEvolution = once('labdoo-node-evolution', '#nodeEvolutionChart', context);
      if (ctxNodeEvolution.length) {
        new Chart(ctxNodeEvolution[0], {
          type: 'line',
          data: {
            labels: stats.node_evolution_data.labels,
            datasets: [{
              label: Drupal.t('New content items'),
              data: stats.node_evolution_data.values,
              borderColor: 'rgb(201, 203, 207)',
              tension: 0.1,
              fill: false
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

      // 18. Content Type Composition
      const ctxContentType = once('labdoo-content-type', '#contentTypeChart', context);
      if (ctxContentType.length) {
        new Chart(ctxContentType[0], {
          type: 'polarArea',
          data: {
            labels: stats.content_type_data.labels,
            datasets: [{
              data: stats.content_type_data.values,
              backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#C9CBCF'
              ]
            }]
          },
          options: { responsive: true, maintainAspectRatio: false }
        });
      }

    }
  };
})(jQuery, Drupal, drupalSettings);
