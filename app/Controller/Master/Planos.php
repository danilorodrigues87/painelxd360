<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Common\ProductModules;
use App\Common\Helpers\ModuleGateHelper;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\ClientesAssinantes;

class Planos extends Page {

	public static function index($request) {
		if (!PlanosAssinatura::tabelaExiste()) {
			$content = View::render('master/modules/planos/sql', []);
			return parent::getPanel('Planos — Master', $content, 'planos');
		}

		$content = View::render('master/modules/planos/index', [
			'modulos_json' => json_encode(self::catalogoModulos(), JSON_UNESCAPED_UNICODE),
		]);
		return parent::getPanel('Planos — Master', $content, 'planos');
	}

	public static function getInfo($request) {
		if (!PlanosAssinatura::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL de planos_assinatura no phpMyAdmin.',
			]);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		switch ($acao) {
			case 'listar':
				return self::listar();
			case 'detalhes':
				return self::detalhes($post);
			case 'salvar':
				return self::salvar($post);
			case 'excluir':
				return self::excluir($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	/** @return array<int, array{slug:string,label:string}> */
	public static function catalogoModulos(): array {
		$out = [];
		foreach (ProductModules::getCatalog() as $slug => $label) {
			$out[] = ['slug' => $slug, 'label' => $label];
		}
		return $out;
	}

	/** @return array<int, array{id:int,nome:string}> */
	public static function listarAtivosResumo(): array {
		if (!PlanosAssinatura::tabelaExiste()) {
			return [];
		}
		$out = [];
		$results = PlanosAssinatura::get('ativo = 1', 'ordem ASC, nome ASC');
		while ($p = $results->fetchObject(PlanosAssinatura::class)) {
			$out[] = [
				'id'            => (int)$p->id,
				'nome'          => $p->nome,
				'todos_modulos' => $p->temTodosModulos(),
				'modulos'       => $p->getSlugs(),
			];
		}
		return $out;
	}

	private static function listar(): string {
		$results = PlanosAssinatura::get(null, 'ordem ASC, nome ASC');
		$lista = [];
		while ($p = $results->fetchObject(PlanosAssinatura::class)) {
			$lista[] = self::formatar($p);
		}
		return json_encode(['success' => true, 'planos' => $lista]);
	}

	private static function detalhes(array $post): string {
		$p = PlanosAssinatura::getById((int)($post['id'] ?? 0));
		if (!$p instanceof PlanosAssinatura) {
			return json_encode(['success' => false, 'message' => 'Plano não encontrado.']);
		}
		return json_encode(['success' => true, 'plano' => self::formatar($p)]);
	}

	private static function salvar(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$modo = trim((string)($post['modo'] ?? ''));
		if ($modo === 'editar' && $id <= 0) {
			return json_encode(['success' => false, 'message' => 'ID do plano inválido. Recarregue a página e tente novamente.']);
		}
		$nome = trim((string)($post['nome'] ?? ''));
		$descricao = trim((string)($post['descricao'] ?? ''));
		$descricaoDetalhada = trim((string)($post['descricao_detalhada'] ?? ''));
		$ordem = (int)($post['ordem'] ?? 0);
		$ativo = !empty($post['ativo']) ? 1 : 0;
		$todos = !empty($post['todos_modulos']);
		$valorMensal = self::parseValorMensal($post['valor_mensal'] ?? 0);

		if ($nome === '') {
			return json_encode(['success' => false, 'message' => 'Informe o nome do plano.']);
		}

		$modulosJson = null;
		if (!$todos) {
			$slugs = self::parseSlugs($post['modulos_json'] ?? '[]');
			if (empty($slugs)) {
				return json_encode(['success' => false, 'message' => 'Selecione módulos ou marque “Todos”.']);
			}
			$modulosJson = json_encode($slugs, JSON_UNESCAPED_UNICODE);
		}

		if ($id > 0) {
			$ob = PlanosAssinatura::getById($id);
			if (!$ob instanceof PlanosAssinatura) {
				return json_encode(['success' => false, 'message' => 'Plano não encontrado.']);
			}
		} else {
			$ob = new PlanosAssinatura;
		}

		$ob->nome = $nome;
		$ob->descricao = $descricao !== '' ? $descricao : null;
		if (PlanosAssinatura::temColunaDescricaoDetalhada()) {
			$ob->descricao_detalhada = $descricaoDetalhada !== '' ? $descricaoDetalhada : null;
		}
		$ob->modulos = $modulosJson;
		$ob->ativo = $ativo;
		$ob->ordem = $ordem;
		if (PlanosAssinatura::temColunaValorMensal()) {
			$ob->valor_mensal = $valorMensal;
		}

		if ($id > 0) {
			$ob->atualizar();
			self::reescreverEscolasDoPlano($ob);
			return json_encode(['success' => true, 'message' => 'Plano atualizado. Clientes vinculados foram sincronizados.', 'plano_id' => (int)$ob->id, 'plano' => self::formatar($ob)]);
		}

		$ob->cadastrar();
		return json_encode(['success' => true, 'message' => 'Plano criado.', 'plano_id' => (int)$ob->id, 'plano' => self::formatar($ob)]);
	}

	private static function excluir(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$ob = PlanosAssinatura::getById($id);
		if (!$ob instanceof PlanosAssinatura) {
			return json_encode(['success' => false, 'message' => 'Plano não encontrado.']);
		}

		if (ClientesAssinantes::temColunaPlanId()) {
			$qtd = ClientesAssinantes::getEscolas('plan_id = '.$id, null, null, 'COUNT(*) AS qtd')
				->fetch(\PDO::FETCH_ASSOC);
			if ((int)($qtd['qtd'] ?? 0) > 0) {
				return json_encode([
					'success' => false,
					'message' => 'Há clientes vinculados a este plano. Desvincule-os antes de excluir.',
				]);
			}
		}

		$ob->excluir();
		return json_encode(['success' => true, 'message' => 'Plano excluído.']);
	}

	private static function reescreverEscolasDoPlano(PlanosAssinatura $plano): void {
		if (!ClientesAssinantes::temColunaPlanId()) {
			return;
		}
		$mods = $plano->modulosParaEscola();
		$results = ClientesAssinantes::getEscolas('plan_id = '.(int)$plano->id);
		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			$e->modulos_liberados = $mods;
			$e->atualizar();
			ModuleGateHelper::limparCache((int)$e->id);
			ModuleGateHelper::sincronizarAcessoDiretores((int)$e->id);
		}
	}

	private static function formatar(PlanosAssinatura $p): array {
		$valor = PlanosAssinatura::temColunaValorMensal()
			? round((float)($p->valor_mensal ?? 0), 2)
			: 0.0;
		return [
			'id'            => (int)$p->id,
			'nome'          => $p->nome,
			'descricao'     => $p->descricao,
			'descricao_detalhada' => PlanosAssinatura::temColunaDescricaoDetalhada()
				? ($p->descricao_detalhada ?? null)
				: null,
			'valor_mensal'  => $valor,
			'valor_br'      => number_format($valor, 2, ',', '.'),
			'ativo'         => (int)$p->ativo ? 1 : 0,
			'ordem'         => (int)$p->ordem,
			'todos_modulos' => $p->temTodosModulos(),
			'modulos'       => $p->temTodosModulos() ? [] : $p->getSlugs(),
			'modulos_qtd'   => $p->temTodosModulos()
				? count(ProductModules::getSlugs())
				: count($p->getSlugs()),
		];
	}

	private static function parseValorMensal($raw): float {
		if (is_numeric($raw)) {
			return max(0, round((float)$raw, 2));
		}
		$s = trim((string)$raw);
		if ($s === '') {
			return 0.0;
		}
		$s = str_replace(['R$', ' '], '', $s);
		if (strpos($s, ',') !== false) {
			$s = str_replace('.', '', $s);
			$s = str_replace(',', '.', $s);
		}
		return max(0, round((float)$s, 2));
	}

	/** @return string[] */
	private static function parseSlugs($raw): array {
		$arr = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
		$validos = array_flip(ProductModules::getSlugs());
		$out = [];
		foreach ($arr as $s) {
			$s = (string)$s;
			if (isset($validos[$s])) {
				$out[$s] = true;
			}
		}
		return array_keys($out);
	}
}
