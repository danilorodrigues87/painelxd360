<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Model\Entity\Estoque\Stq_Categorias;
use App\Model\Entity\Estoque\Stq_Movimentacoes;
use App\Model\Entity\Estoque\Stq_PrecoHistorico;
use App\Model\Entity\Estoque\Stq_Produtos;

class EstoqueProdutos extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$ok = in_array('Estoque', $mods, true);
		if (!$ok && ($user['usuario']['nivel'] ?? '') === 'Diretor') {
			$ok = in_array('estoque', ModuleGateHelper::getSlugsEscola($idAdmin), true);
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

	private static function dinheiro($v): float {
		return (float)str_replace(',', '.', (string)$v);
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/estoque/produtos', []);
		return parent::getPanel('Estoque', $content, 'vendas', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return self::json(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'listar') {
			return self::listar($idAdmin, $post);
		}
		if ($acao === 'categorias') {
			return self::listarCategorias($idAdmin);
		}
		if ($acao === 'salvar_categoria') {
			return self::salvarCategoria($idAdmin, $post);
		}
		if ($acao === 'salvar_produto') {
			return self::salvarProduto($idAdmin, $post);
		}
		if ($acao === 'inativar_produto') {
			return self::inativarProduto($idAdmin, $post);
		}
		if ($acao === 'movimentar') {
			return self::movimentar($idAdmin, $post);
		}
		if ($acao === 'historico') {
			return self::historico($idAdmin, $post);
		}

		return self::json(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function listar(int $idAdmin, array $post): string {
		$q = trim((string)($post['q'] ?? ''));
		$where = 'id_admin = ' . (int)$idAdmin . ' AND status = 1';
		if ($q !== '') {
			$esc = addslashes($q);
			$where .= " AND (nome LIKE '%{$esc}%' OR sku LIKE '%{$esc}%')";
		}
		$rows = [];
		$res = Stq_Produtos::getAll($where, 'nome ASC');
		while ($p = $res->fetchObject(Stq_Produtos::class)) {
			$catNome = '';
			if (!empty($p->id_categoria)) {
				$c = Stq_Categorias::getStqCategoriaById((int)$p->id_categoria);
				if ($c && (int)$c->id_admin === $idAdmin) {
					$catNome = (string)$c->nome;
				}
			}
			$rows[] = [
				'id' => (int)$p->id,
				'nome' => (string)$p->nome,
				'sku' => (string)($p->sku ?? ''),
				'id_categoria' => $p->id_categoria ? (int)$p->id_categoria : null,
				'categoria' => $catNome,
				'quantidade' => (int)$p->quantidade,
				'valor_custo' => (float)$p->valor_custo,
				'valor_venda' => (float)$p->valor_venda,
				'descricao' => (string)($p->descricao ?? ''),
			];
		}
		return self::json(['success' => true, 'produtos' => $rows]);
	}

	private static function listarCategorias(int $idAdmin): string {
		$rows = [];
		$res = Stq_Categorias::getStqCategorias(
			'id_admin = ' . (int)$idAdmin . ' AND status = 1',
			'nome ASC'
		);
		while ($c = $res->fetchObject(Stq_Categorias::class)) {
			$rows[] = [
				'id' => (int)$c->id,
				'nome' => (string)$c->nome,
				'descricao' => (string)($c->descricao ?? ''),
			];
		}
		return self::json(['success' => true, 'categorias' => $rows]);
	}

	private static function salvarCategoria(int $idAdmin, array $post): string {
		$nome = trim((string)($post['nome'] ?? ''));
		if ($nome === '') {
			return self::json(['success' => false, 'message' => 'Informe o nome da categoria.']);
		}
		$id = (int)($post['id'] ?? 0);
		if ($id > 0) {
			$c = Stq_Categorias::getStqCategoriaById($id);
			if (!$c || (int)$c->id_admin !== $idAdmin) {
				return self::json(['success' => false, 'message' => 'Categoria não encontrada.']);
			}
			$c->nome = $nome;
			$c->descricao = trim((string)($post['descricao'] ?? ''));
			$c->status = 1;
			$c->atualizar();
			return self::json(['success' => true, 'message' => 'Categoria atualizada.', 'id' => $id]);
		}
		$c = new Stq_Categorias();
		$c->nome = $nome;
		$c->descricao = trim((string)($post['descricao'] ?? ''));
		$c->status = 1;
		$c->id_admin = $idAdmin;
		$c->cadastrar();
		return self::json(['success' => true, 'message' => 'Categoria criada.', 'id' => (int)$c->id]);
	}

	private static function salvarProduto(int $idAdmin, array $post): string {
		$nome = trim((string)($post['nome'] ?? ''));
		if ($nome === '') {
			return self::json(['success' => false, 'message' => 'Informe o nome do produto.']);
		}
		$sku = trim((string)($post['sku'] ?? ''));
		$idCategoria = (int)($post['id_categoria'] ?? 0);
		if ($idCategoria > 0) {
			$cat = Stq_Categorias::getStqCategoriaById($idCategoria);
			if (!$cat || (int)$cat->id_admin !== $idAdmin || (int)$cat->status !== 1) {
				return self::json(['success' => false, 'message' => 'Categoria inválida.']);
			}
		} else {
			$idCategoria = null;
		}

		$valorCusto = self::dinheiro($post['valor_custo'] ?? 0);
		$valorVenda = self::dinheiro($post['valor_venda'] ?? 0);
		if ($valorVenda < 0 || $valorCusto < 0) {
			return self::json(['success' => false, 'message' => 'Valores inválidos.']);
		}

		if ($sku !== '') {
			$existSku = Stq_Produtos::getBySku($sku, $idAdmin);
			$id = (int)($post['id'] ?? 0);
			if ($existSku && (int)$existSku->id !== $id) {
				return self::json(['success' => false, 'message' => 'SKU já usado em outro produto.']);
			}
		}

		$id = (int)($post['id'] ?? 0);
		$qtdInicial = (int)($post['quantidade'] ?? 0);

		if ($id > 0) {
			$p = Stq_Produtos::getByIdAdmin($id, $idAdmin);
			if (!$p || (int)($p->status ?? 1) !== 1) {
				return self::json(['success' => false, 'message' => 'Produto não encontrado.']);
			}
			$precoMudou = ((float)$p->valor_custo !== $valorCusto) || ((float)$p->valor_venda !== $valorVenda);
			$p->nome = $nome;
			$p->sku = $sku !== '' ? $sku : null;
			$p->id_categoria = $idCategoria;
			$p->descricao = trim((string)($post['descricao'] ?? ''));
			$p->valor_custo = $valorCusto;
			$p->valor_venda = $valorVenda;
			$p->status = 1;
			$p->atualizar();
			if ($precoMudou) {
				Stq_PrecoHistorico::registrar($id, $valorCusto, $valorVenda, $idAdmin);
			}
			return self::json(['success' => true, 'message' => 'Produto atualizado.', 'id' => $id]);
		}

		$p = new Stq_Produtos();
		$p->nome = $nome;
		$p->sku = $sku !== '' ? $sku : null;
		$p->id_categoria = $idCategoria;
		$p->descricao = trim((string)($post['descricao'] ?? ''));
		$p->valor_custo = $valorCusto;
		$p->valor_venda = $valorVenda;
		$p->quantidade = max(0, $qtdInicial);
		$p->status = 1;
		$p->id_admin = $idAdmin;
		$p->cadastrar();
		Stq_PrecoHistorico::registrar((int)$p->id, $valorCusto, $valorVenda, $idAdmin);
		if ($qtdInicial > 0) {
			Stq_Movimentacoes::registrarMovimentacao(
				(int)$p->id,
				'entrada',
				$qtdInicial,
				0,
				$qtdInicial,
				'Saldo inicial',
				$idAdmin
			);
		}
		return self::json(['success' => true, 'message' => 'Produto criado.', 'id' => (int)$p->id]);
	}

	private static function inativarProduto(int $idAdmin, array $post): string {
		$id = (int)($post['id'] ?? 0);
		$p = Stq_Produtos::getByIdAdmin($id, $idAdmin);
		if (!$p) {
			return self::json(['success' => false, 'message' => 'Produto não encontrado.']);
		}
		$p->inativar();
		return self::json(['success' => true, 'message' => 'Produto inativado.']);
	}

	private static function movimentar(int $idAdmin, array $post): string {
		$id = (int)($post['id_produto'] ?? 0);
		$tipo = (string)($post['tipo'] ?? '');
		$qtd = (int)($post['quantidade'] ?? 0);
		$obs = trim((string)($post['observacao'] ?? ''));
		$p = Stq_Produtos::getByIdAdmin($id, $idAdmin);
		if (!$p || (int)($p->status ?? 1) !== 1) {
			return self::json(['success' => false, 'message' => 'Produto não encontrado.']);
		}
		try {
			if ($tipo === 'entrada') {
				$p->entradaEstoque($qtd, $idAdmin, $obs !== '' ? $obs : 'Entrada manual');
			} elseif ($tipo === 'ajuste') {
				$p->ajustarEstoque($qtd, $idAdmin, $obs !== '' ? $obs : 'Ajuste manual');
			} else {
				return self::json(['success' => false, 'message' => 'Tipo de movimentação inválido.']);
			}
		} catch (\Throwable $e) {
			return self::json(['success' => false, 'message' => $e->getMessage()]);
		}
		return self::json([
			'success' => true,
			'message' => 'Estoque atualizado.',
			'quantidade' => (int)$p->quantidade,
		]);
	}

	private static function historico(int $idAdmin, array $post): string {
		$id = (int)($post['id_produto'] ?? 0);
		$p = Stq_Produtos::getByIdAdmin($id, $idAdmin);
		if (!$p) {
			return self::json(['success' => false, 'message' => 'Produto não encontrado.']);
		}
		$rows = [];
		$res = Stq_Movimentacoes::getByProduto($id, '0,30');
		while ($m = $res->fetchObject(Stq_Movimentacoes::class)) {
			if ((int)$m->id_admin !== $idAdmin) {
				continue;
			}
			$rows[] = [
				'id' => (int)$m->id,
				'tipo' => (string)$m->tipo,
				'quantidade' => (int)$m->quantidade,
				'saldo_anterior' => (int)$m->saldo_anterior,
				'saldo_atual' => (int)$m->saldo_atual,
				'observacao' => (string)($m->observacao ?? ''),
				'created_at' => (string)$m->created_at,
			];
		}
		return self::json(['success' => true, 'movimentacoes' => $rows]);
	}
}
