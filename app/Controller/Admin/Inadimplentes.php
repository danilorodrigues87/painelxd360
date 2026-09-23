<?php

namespace App\Controller\Admin;

use App\Http\Response;
use App\Utils\View;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\InadimplentesHelper;
use App\Model\Entity\Trilhas as EntityTrilhas;

class Inadimplentes extends Page {

	public static function index($request) {
		$idAdmin = TenantHelper::getIdAdmin();
		$trilhasOpts = '<option value="">Todos os cursos</option>';
		$rs = EntityTrilhas::getTrilha('id_admin = '.(int)$idAdmin, 'nome ASC');
		while ($t = $rs->fetchObject(EntityTrilhas::class)) {
			$trilhasOpts .= '<option value="'.(int)$t->id.'">'.htmlspecialchars((string)$t->nome, ENT_QUOTES, 'UTF-8').'</option>';
		}

		$content = View::render('admin/modules/financeiro/inadimplentes', [
			'trilhas_options' => $trilhasOpts,
		]);

		return parent::getPanel('Inadimplentes', $content, 'Financeiro', $request);
	}

	public static function getInfo($request) {
		$idAdmin = TenantHelper::getIdAdmin();
		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		if ($acao === 'listar_inadimplentes') {
			$res = InadimplentesHelper::listarInadimplentes($idAdmin, $post);
			return json_encode([
				'success' => !empty($res['ok']),
				'linhas' => $res['linhas'] ?? [],
				'total_registros' => $res['total_registros'] ?? 0,
				'page' => $res['page'] ?? 1,
				'per_page' => $res['per_page'] ?? 50,
				'totais' => $res['totais'] ?? [],
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'listar_abandono') {
			$res = InadimplentesHelper::listarAbandono($idAdmin, $post);
			return json_encode([
				'success' => !empty($res['ok']),
				'linhas' => $res['linhas'] ?? [],
				'total_registros' => $res['total_registros'] ?? 0,
				'page' => $res['page'] ?? 1,
				'per_page' => $res['per_page'] ?? 50,
				'totais' => $res['totais'] ?? [],
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'exportar_csv') {
			$aba = (string)($post['aba'] ?? 'inadimplentes');
			if ($aba === 'abandono') {
				$post['per_page'] = 0;
				$res = InadimplentesHelper::listarAbandono($idAdmin, $post);
				$csv = InadimplentesHelper::linhasParaCsv(
					$res['linhas'] ?? [],
					InadimplentesHelper::colunasCsvAbandono()
				);
				$nome = 'abandono_'.date('Y-m-d').'.csv';
			} else {
				$post['per_page'] = 0;
				$res = InadimplentesHelper::listarInadimplentes($idAdmin, $post);
				$linhas = $res['linhas'] ?? [];
				foreach ($linhas as &$ln) {
					$ln['tem_acordo_vencido'] = !empty($ln['tem_acordo_vencido']) ? 'Sim' : 'Não';
				}
				unset($ln);
				$csv = InadimplentesHelper::linhasParaCsv(
					$linhas,
					InadimplentesHelper::colunasCsvInadimplentes()
				);
				$nome = 'inadimplentes_'.date('Y-m-d').'.csv';
			}

			$res = new Response(200, $csv, 'text/csv; charset=utf-8');
			$res->addHeader('Content-Disposition', 'attachment; filename="'.$nome.'"');
			return $res;
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
	}
}
