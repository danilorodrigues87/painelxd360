<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\FinanceiroAlunoHelper;
use App\Common\Helpers\EncargosContratoHelper;
use App\Model\Entity\User as EntityUser;

class AlunoExtrato extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$ok = in_array('Carnês', $mods, true)
			|| in_array('Entrada', $mods, true)
			|| in_array('Alunos', $mods, true)
			|| (($user['usuario']['nivel'] ?? '') === 'Diretor');
		if (!$ok) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request, $idAluno) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$idAluno = (int)$idAluno;
		$aluno = EntityUser::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			$request->getRouter()->redirect('/painel/clientes');
			return '';
		}
		$content = View::render('admin/modules/financeiro/aluno-extrato', [
			'id_aluno' => (string)$idAluno,
			'nome_aluno' => htmlspecialchars((string)$aluno->nome, ENT_QUOTES, 'UTF-8'),
			'email_aluno' => htmlspecialchars((string)$aluno->email, ENT_QUOTES, 'UTF-8'),
		]);
		return parent::getPanel('Carnês', $content, 'Financeiro', $request);
	}

	public static function getInfo($request, $idAluno) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$idAluno = (int)$idAluno;
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'extrato') {
			$res = FinanceiroAlunoHelper::extrato($idAdmin, $idAluno);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'aluno' => $res['aluno'] ?? null,
				'matriculas' => $res['matriculas'] ?? [],
				'acordos' => $res['acordos'] ?? [],
				'titulos' => $res['titulos'] ?? [],
				'totais' => $res['totais'] ?? [],
				'pode_renegociar' => !empty($res['pode_renegociar']),
				'hoje' => $res['hoje'] ?? date('Y-m-d'),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($acao === 'renegociar') {
			$ids = $post['ids_titulos'] ?? [];
			if (is_string($ids)) {
				$decoded = json_decode($ids, true);
				$ids = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $ids);
			}
			if (!is_array($ids)) {
				$ids = [];
			}
			$res = FinanceiroAlunoHelper::renegociar(
				$idAdmin,
				$idAluno,
				$ids,
				(float)str_replace(',', '.', (string)($post['valor_total'] ?? 0)),
				(float)str_replace(',', '.', (string)($post['valor_entrada'] ?? 0)),
				(int)($post['qtd_parcelas_restante'] ?? $post['qtd_parcelas'] ?? 1),
				(string)($post['primeiro_vencimento'] ?? ''),
				(string)($post['observacao'] ?? '')
			);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'id_acordo' => $res['id_acordo'] ?? null,
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'divida_atualizada') {
			$res = EncargosContratoHelper::calcularDividaAluno(
				$idAdmin,
				$idAluno,
				(string)($post['data_referencia'] ?? '')
			);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'total_face' => $res['total_face'] ?? 0,
				'total_multa' => $res['total_multa'] ?? 0,
				'total_juros' => $res['total_juros'] ?? 0,
				'total_com_encargos' => $res['total_com_encargos'] ?? 0,
				'qtd_abertos' => $res['qtd_abertos'] ?? 0,
				'qtd_vencidos' => $res['qtd_vencidos'] ?? 0,
				'data_referencia' => $res['data_referencia'] ?? date('Y-m-d'),
				'titulos' => $res['titulos'] ?? [],
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'encargos_titulo') {
			$res = EncargosContratoHelper::encargosParaTitulo(
				$idAdmin,
				$idAluno,
				(int)($post['id_titulo'] ?? 0),
				(string)($post['data_referencia'] ?? '')
			);
			if (empty($res['ok'])) {
				return json_encode([
					'success' => false,
					'message' => $res['message'] ?? 'Falha ao simular encargos.',
				], JSON_UNESCAPED_UNICODE);
			}
			$html = '';
			if (!empty($res['elegivel_encargos'])) {
				$html = EncargosContratoHelper::htmlBlocoPagamento($res);
			}
			return json_encode([
				'success' => true,
				'elegivel_encargos' => !empty($res['elegivel_encargos']),
				'valor_face' => $res['valor_face'] ?? 0,
				'multa' => $res['multa'] ?? 0,
				'juros' => $res['juros'] ?? 0,
				'total_com_encargos' => $res['total_com_encargos'] ?? 0,
				'total_sem_encargos' => $res['total_sem_encargos'] ?? 0,
				'dias_cobraveis' => $res['dias_cobraveis'] ?? 0,
				'html_bloco' => $html,
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'dar_baixa') {
			$res = FinanceiroAlunoHelper::darBaixa(
				$idAdmin,
				$idAluno,
				(int)($post['id_titulo'] ?? 0),
				(float)str_replace(',', '.', (string)($post['valor_pago'] ?? 0)),
				(string)($post['tipo_pagamento'] ?? ''),
				(string)($post['data_pagamento'] ?? '')
			);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'dar_baixa_lote') {
			$ids = $post['ids_titulos'] ?? [];
			if (is_string($ids)) {
				$decoded = json_decode($ids, true);
				$ids = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $ids);
			}
			if (!is_array($ids)) {
				$ids = [];
			}
			$res = FinanceiroAlunoHelper::darBaixaLote(
				$idAdmin,
				$idAluno,
				$ids,
				(string)($post['tipo_pagamento'] ?? ''),
				(string)($post['data_pagamento'] ?? '')
			);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'baixados' => $res['baixados'] ?? 0,
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}
}
