<?php

namespace App\Controller\Admin;

use App\Common\Helpers\AiUsageLogger;
use App\Common\Helpers\DateTimeHelper;
use App\Model\Db\Pagination;
use App\Model\Entity\AiUsageLog;
use App\Utils\View;

class RelatorioIa extends Page {

	public static function index($request) {
		$ok = AiUsageLog::tabelaExiste();
		$alertSql = $ok ? '' : '<div class="alert alert-warning">Execute <code>database/ai_usage_log.sql</code> no phpMyAdmin para habilitar o relatório.</div>';
		$content = View::render('admin/modules/config/ia-uso', [
			'alert_sql' => $alertSql,
		]);
		return parent::getPanel('Configurações de IA', $content, 'config', $request);
	}

	private static function montarFiltros(array $postVars): string {
		$parts = [];

		$de = trim((string)($postVars['de'] ?? ''));
		$ate = trim((string)($postVars['ate'] ?? ''));
		if ($de !== '' && $ate !== '') {
			$parts[] = 'created_at >= "'.addslashes($de).' 00:00:00"';
			$parts[] = 'created_at <= "'.addslashes($ate).' 23:59:59"';
		} else {
			$parts[] = 'created_at >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01 00:00:00")';
			$parts[] = 'created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, "%Y-%m-01"), INTERVAL 1 MONTH)';
		}

		$feature = trim((string)($postVars['feature'] ?? ''));
		if ($feature !== '') {
			$parts[] = 'feature = "'.addslashes($feature).'"';
		}

		$provider = trim((string)($postVars['provider'] ?? ''));
		if ($provider !== '') {
			$parts[] = 'provider = "'.addslashes($provider).'"';
		}

		return implode(' AND ', $parts);
	}

	public static function getInfo($request) {
		try {
		$idAdmin = parent::getIdAdminInt();
		if (!AiUsageLog::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/ai_usage_log.sql no banco.',
				'itens' => '',
				'resumo' => [],
				'pagination' => '',
			], JSON_UNESCAPED_UNICODE);
		}

		$postVars = $request->getPostVars();
		$paginaAtual = (int)($postVars['page'] ?? 1);
		$whereExtra = self::montarFiltros($postVars);

		$resumo = AiUsageLog::resumo($idAdmin, $whereExtra);

		$whereCount = 'id_admin = '.(int)$idAdmin.' AND '.$whereExtra;
		$total = (int)(new \App\Model\Db\Database('ai_usage_log'))->select(
			$whereCount,
			null,
			null,
			'COUNT(*) as qtd'
		)->fetchObject()->qtd;

		$limite = (int)(getenv('PAGINATION_LIMIT') ?: 10);
		$obPagination = new Pagination($total, $paginaAtual, $limite);
		$results = AiUsageLog::listar($idAdmin, $whereExtra, $obPagination->getLimit());

		$rows = '';
		while ($row = $results->fetchObject()) {
			$ok = (int)($row->success ?? 0) === 1;
			$tokens = (int)($row->total_tokens ?? 0);
			if ($tokens <= 0 && (int)($row->chars_in ?? 0) > 0) {
				$tokensLabel = (int)$row->chars_in.' chars';
			} else {
				$tokensLabel = number_format($tokens, 0, ',', '.');
			}
			$rows .= '<tr>'
				.'<td>'.htmlspecialchars(DateTimeHelper::databr($row->created_at ?? '').' '.DateTimeHelper::extrairHorario($row->created_at ?? '')).'</td>'
				.'<td>'.htmlspecialchars(AiUsageLogger::labelFeature((string)($row->feature ?? ''))).'</td>'
				.'<td>'.htmlspecialchars((string)($row->provider ?? '')).'</td>'
				.'<td><small>'.htmlspecialchars((string)($row->model ?? '')).'</small></td>'
				.'<td class="text-end">'.$tokensLabel.'</td>'
				.'<td class="text-end">'.(int)($row->latency_ms ?? 0).' ms</td>'
				.'<td>'.($ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger" title="'.htmlspecialchars((string)($row->error_snippet ?? '')).'">Erro</span>').'</td>'
				.'</tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro no período.</td></tr>';
		}

		$table = '<div class="table-responsive"><table class="table table-sm table-striped">'
			.'<thead><tr>'
			.'<th>Data</th><th>Recurso</th><th>Provedor</th><th>Modelo</th>'
			.'<th class="text-end">Tokens/chars</th><th class="text-end">Latência</th><th>Status</th>'
			.'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

		return json_encode([
			'success'    => true,
			'itens'      => $table,
			'resumo'     => $resumo,
			'pagination' => parent::getPagination($request, $obPagination),
		], JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $e) {
			return json_encode([
				'success' => false,
				'message' => 'Erro ao carregar relatório: '.$e->getMessage(),
				'itens' => '',
				'resumo' => [],
				'pagination' => '',
			], JSON_UNESCAPED_UNICODE);
		}
	}
}
