var dashboardCharts = {};



function temaEscuroAtivo() {

	return document.documentElement.getAttribute('data-bs-theme') === 'dark';

}



function coresChart() {

	var dark = temaEscuroAtivo();

	return {

		font: dark ? '#e8ecf1' : '#292b2c',

		muted: dark ? '#a8b0bd' : '#6c757d',

		grid: dark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.125)',

		gridZero: dark ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.25)'

	};

}



function aplicarDefaultsChart() {

	if (typeof Chart === 'undefined') {

		return;

	}

	var c = coresChart();

	Chart.defaults.global.defaultFontFamily = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';

	Chart.defaults.global.defaultFontColor = c.font;

}



aplicarDefaultsChart();



function destruirChart(chave) {

	if (dashboardCharts[chave]) {

		dashboardCharts[chave].destroy();

		delete dashboardCharts[chave];

	}

}



function serieTodaZero(valores) {

	if (!valores || !valores.length) {

		return true;

	}

	for (var i = 0; i < valores.length; i++) {

		if (Number(valores[i]) > 0) {

			return false;

		}

	}

	return true;

}



function toggleChartEmpty(canvasId, vazio) {

	var $msg = $('.chart-empty-msg[data-for="' + canvasId + '"]');

	var canvas = document.getElementById(canvasId);

	if ($msg.length) {

		$msg.toggleClass('d-none', !vazio);

	}

	if (canvas) {

		canvas.style.display = vazio ? 'none' : '';

	}

}



function carregarDashboard(periodo) {

	if (typeof carregaDadosHome === 'undefined') {

		return;

	}

	if (typeof Chart === 'undefined') {

		console.error('Chart.js não carregou (vendor/chart.min.js).');

		return;

	}



	aplicarDefaultsChart();

	periodo = periodo || 'ano';



	$.ajax({

		type: 'POST',

		url: url_base + carregaDadosHome,

		data: { periodo: periodo },

		dataType: 'json',

		success: function(result) {

			if (!result) {

				return;

			}

			graficoVendas(result);

			graficoFinanca(result);

			graficoTopVendas(result);

			graficoCrmStatus(result);

			graficoCrmOrigem(result);

			renderTopVendedores(result);

		},

		error: function(xhr) {

			console.error('Erro ao carregar gráficos:', url_base + carregaDadosHome, xhr.status, xhr.responseText);

		}

	});

}



$(document).ready(function() {

	if (typeof carregaDadosHome === 'undefined') {

		return;

	}



	carregarDashboard($('#filtro-periodo-dashboard').val() || 'ano');



	$('#filtro-periodo-dashboard').on('change', function() {

		carregarDashboard($(this).val());

	});



	document.addEventListener('painel-theme-change', function() {

		carregarDashboard($('#filtro-periodo-dashboard').val() || 'ano');

	});

});



function graficoVendas(result) {

	var ctx = document.getElementById('graficoVendas');

	if (!ctx) return;



	destruirChart('vendas');



	var valores = result.vendas_valores || [];

	var vazio = serieTodaZero(valores);

	toggleChartEmpty('graficoVendas', vazio);

	if (vazio) {

		return;

	}



	var maxValor = Math.max.apply(null, valores.concat([5]));

	var c = coresChart();



	dashboardCharts.vendas = new Chart(ctx, {

		type: 'line',

		data: {

			labels: result.vendas_meses || [],

			datasets: [{

				label: 'Matrícula(s)',

				lineTension: 0.3,

				backgroundColor: 'rgba(2,117,216,0.2)',

				borderColor: 'rgba(2,117,216,1)',

				pointRadius: 5,

				pointBackgroundColor: 'rgba(2,117,216,1)',

				pointBorderColor: 'rgba(255,255,255,0.8)',

				pointHoverRadius: 5,

				pointHoverBackgroundColor: 'rgba(2,117,216,1)',

				pointHitRadius: 50,

				pointBorderWidth: 2,

				data: valores

			}]

		},

		options: {

			scales: {

				xAxes: [{

					gridLines: { display: false },

					ticks: { maxTicksLimit: 7, fontColor: c.font }

				}],

				yAxes: [{

					ticks: {

						min: 0,

						max: maxValor,

						precision: 0,

						beginAtZero: true,

						fontColor: c.font

					},

					gridLines: {

						color: c.grid,

						zeroLineColor: c.gridZero

					}

				}]

			},

			legend: { display: false }

		}

	});

}



function graficoFinanca(result) {

	var ctx = document.getElementById('graficoFinanceiro');

	if (!ctx) return;



	destruirChart('financas');



	var valores = result.financas_valores || [];

	var vazio = serieTodaZero(valores);

	toggleChartEmpty('graficoFinanceiro', vazio);

	if (vazio) {

		return;

	}



	var c = coresChart();



	dashboardCharts.financas = new Chart(ctx, {

		type: 'bar',

		data: {

			labels: result.financas_meses || [],

			datasets: [{

				label: 'Entrada',

				backgroundColor: 'rgba(2,117,216,1)',

				borderColor: 'rgba(2,117,216,1)',

				data: valores

			}]

		},

		options: {

			scales: {

				xAxes: [{

					gridLines: { display: false },

					ticks: { maxTicksLimit: 6, fontColor: c.font }

				}],

				yAxes: [{

					ticks: {

						min: 0,

						maxTicksLimit: 5,

						fontColor: c.font,

						callback: function(value) {

							return value.toLocaleString('pt-BR', {

								style: 'currency',

								currency: 'BRL'

							});

						}

					},

					gridLines: {

						display: true,

						color: c.grid,

						zeroLineColor: c.gridZero

					}

				}]

			},

			legend: { display: false },

			tooltips: {

				callbacks: {

					label: function(tooltipItem) {

						var value = tooltipItem.yLabel || 0;

						return value.toLocaleString('pt-BR', {

							style: 'currency',

							currency: 'BRL'

						});

					}

				}

			}

		}

	});

}



function graficoTopVendas(result) {

	var ctx = document.getElementById('myPieChart');

	if (!ctx) return;



	destruirChart('topCursos');



	var c = coresChart();



	dashboardCharts.topCursos = new Chart(ctx, {

		type: 'pie',

		data: {

			labels: result.top_produtos || [],

			datasets: [{

				data: result.top_porcentagem || [],

				backgroundColor: result.top_cores || []

			}]

		},

		options: {

			legend: {

				labels: { fontColor: c.font }

			},

			tooltips: {

				callbacks: {

					label: function(tooltipItem, data) {

						var label = data.labels[tooltipItem.index] || '';

						var value = data.datasets[0].data[tooltipItem.index] || 0;

						return label + ': ' + value + '%';

					}

				}

			}

		}

	});

}



function graficoCrmStatus(result) {

	var ctx = document.getElementById('graficoCrmStatus');

	if (!ctx) return;



	destruirChart('crmStatus');



	var c = coresChart();



	dashboardCharts.crmStatus = new Chart(ctx, {

		type: 'bar',

		data: {

			labels: result.crm_status_labels || [],

			datasets: [{

				label: 'Leads',

				backgroundColor: result.crm_status_cores || ['#0d6efd', '#ffc107', '#198754', '#6c757d'],

				data: result.crm_status_valores || []

			}]

		},

		options: {

			legend: { display: false },

			scales: {

				xAxes: [{

					gridLines: { display: false },

					ticks: { fontColor: c.font }

				}],

				yAxes: [{

					ticks: {

						min: 0,

						precision: 0,

						beginAtZero: true,

						fontColor: c.font

					},

					gridLines: {

						color: c.grid,

						zeroLineColor: c.gridZero

					}

				}]

			}

		}

	});

}



function graficoCrmOrigem(result) {

	var ctx = document.getElementById('graficoCrmOrigem');

	if (!ctx) return;



	destruirChart('crmOrigem');



	var c = coresChart();



	dashboardCharts.crmOrigem = new Chart(ctx, {

		type: 'horizontalBar',

		data: {

			labels: result.origem_labels || [],

			datasets: [{

				label: 'Leads',

				backgroundColor: 'rgba(13, 110, 253, 0.7)',

				data: result.origem_valores || []

			}]

		},

		options: {

			legend: { display: false },

			scales: {

				xAxes: [{

					ticks: {

						min: 0,

						precision: 0,

						beginAtZero: true,

						fontColor: c.font

					},

					gridLines: {

						color: c.grid,

						zeroLineColor: c.gridZero

					}

				}],

				yAxes: [{

					gridLines: { display: false },

					ticks: { fontColor: c.font }

				}]

			}

		}

	});

}



function renderTopVendedores(result) {

	var $tbody = $('#top-vendedores-tbody');

	if (!$tbody.length) return;



	var vendedores = result.top_vendedores || [];

	var html = '';



	if (!vendedores.length) {

		html = '<tr><td colspan="2" class="text-muted text-center py-4">Nenhum vendedor com matrícula neste mês.</td></tr>';

	} else {

		vendedores.forEach(function(v) {

			var iniciais = (v.nome || '?').trim().charAt(0).toUpperCase();

			html += '<tr>' +

				'<td class="text-center fw-bold" style="width:40px;">' + v.posicao + '</td>' +

				'<td>' +

					'<div class="d-flex align-items-center py-2">' +

						'<div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;font-weight:bold;">' + iniciais + '</div>' +

						'<div>' +

							'<p class="fw-bold mb-0">' + escapeHtml(v.nome) + '</p>' +

							'<p class="text-muted mb-0 small">' + escapeHtml(v.email || '') + '</p>' +

							'<span class="badge bg-success">' + v.total + ' matrícula(s)</span>' +

						'</div>' +

					'</div>' +

				'</td>' +

			'</tr>';

		});

	}



	$tbody.html(html);

}



function escapeHtml(texto) {

	if (!texto) return '';

	return String(texto)

		.replace(/&/g, '&amp;')

		.replace(/</g, '&lt;')

		.replace(/>/g, '&gt;')

		.replace(/"/g, '&quot;');

}


