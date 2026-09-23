<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\EstoqueVendaHelper;
use App\Model\Entity\Estoque\Stq_Produtos;
use App\Model\Entity\Estoque\Stq_VendaItens;
use App\Model\Entity\Estoque\Stq_Vendas;

class EstoquePdv extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$ok = in_array('PDV', $mods, true);
		if (!$ok && ($user['usuario']['nivel'] ?? '') === 'Diretor') {
			$ok = in_array('vendas', ModuleGateHelper::getSlugsEscola($idAdmin), true);
		}
		if (!$ok) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/estoque/pdv', []);
		return parent::getPanel('PDV', $content, 'vendas', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return self::json(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'buscar') {
			return self::buscar($idAdmin, $post);
		}
		if ($acao === 'finalizar') {
			return self::finalizar($idAdmin, $post);
		}
		if ($acao === 'ultimas') {
			return self::ultimas($idAdmin);
		}

		return self::json(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function buscar(int $idAdmin, array $post): string {
		$q = trim((string)($post['q'] ?? ''));
		$where = 'id_admin = ' . (int)$idAdmin . ' AND status = 1 AND quantidade > 0';
		if ($q !== '') {
			$esc = addslashes($q);
			$where .= " AND (nome LIKE '%{$esc}%' OR sku LIKE '%{$esc}%')";
		}
		$rows = [];
		$res = Stq_Produtos::getAll($where, 'nome ASC', '0,40');
		while ($p = $res->fetchObject(Stq_Produtos::class)) {
			$rows[] = [
				'id' => (int)$p->id,
				'nome' => (string)$p->nome,
				'sku' => (string)($p->sku ?? ''),
				'quantidade' => (int)$p->quantidade,
				'valor_venda' => (float)$p->valor_venda,
			];
		}
		return self::json(['success' => true, 'produtos' => $rows]);
	}

	private static function finalizar(int $idAdmin, array $post): string {
		$itens = $post['itens'] ?? [];
		if (is_string($itens)) {
			$decoded = json_decode($itens, true);
			$itens = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($itens)) {
			$itens = [];
		}
		$res = EstoqueVendaHelper::finalizar(
			$idAdmin,
			$itens,
			(string)($post['tipo_pagamento'] ?? ''),
			(string)($post['observacao'] ?? '')
		);
		return self::json([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'id_venda' => $res['id_venda'] ?? null,
			'id_caixa' => $res['id_caixa'] ?? null,
			'total' => $res['total'] ?? null,
		]);
	}

	private static function ultimas(int $idAdmin): string {
		$rows = [];
		$res = Stq_Vendas::getAll('id_admin = ' . (int)$idAdmin, 'id DESC', '0,10');
		while ($v = $res->fetchObject(Stq_Vendas::class)) {
			$itens = [];
			$ir = Stq_VendaItens::getByVenda((int)$v->id);
			while ($it = $ir->fetchObject(Stq_VendaItens::class)) {
				$itens[] = [
					'nome' => (string)$it->nome_snapshot,
					'qtd' => (int)$it->qtd,
					'subtotal' => (float)$it->subtotal,
				];
			}
			$rows[] = [
				'id' => (int)$v->id,
				'total' => (float)$v->total,
				'tipo_pagamento' => (string)$v->tipo_pagamento,
				'id_caixa' => $v->id_caixa ? (int)$v->id_caixa : null,
				'created_at' => (string)$v->created_at,
				'itens' => $itens,
			];
		}
		return self::json(['success' => true, 'vendas' => $rows]);
	}
}
