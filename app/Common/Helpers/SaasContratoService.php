<?php

namespace App\Common\Helpers;

use App\Common\ProductModules;
use App\Model\Db\Database;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\ProdutoModulo;
use App\Model\Entity\SaasContrato;
use App\Model\Entity\SaasFatura;

class SaasContratoService {

	/** @return array{ok:bool,message:string,contrato?:array} */
	public static function criar(int $idAdmin, array $post): array {
		if (!SaasContrato::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/aplicar_planos_produto.php'];
		}
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return ['ok' => false, 'message' => 'Cliente não encontrado.'];
		}
		$planId = (int)($post['plan_id'] ?? 0);
		$plano = $planId > 0 ? PlanosAssinatura::getById($planId) : null;
		$produto = trim((string)($post['produto_slug'] ?? ''));
		if ($plano instanceof PlanosAssinatura && $plano->ehPlanoDeProduto()) {
			$produto = (string)$plano->produto_slug;
		}
		if ($produto === '' || !isset(ProductModules::getCatalog()[$produto])) {
			return ['ok' => false, 'message' => 'Selecione o produto.'];
		}
		$valor = self::parseValor($post['valor_parcela'] ?? 0);
		if ($valor <= 0) {
			return ['ok' => false, 'message' => 'Informe o valor da parcela.'];
		}
		$ciclo = (($post['ciclo'] ?? '') === 'anual') ? 'anual' : 'mensal';
		$duracao = max(1, (int)($post['duracao_meses'] ?? ($ciclo === 'anual' ? 12 : 1)));
		$qtd = max(1, (int)($post['qtd_parcelas'] ?? 1));
		$inicio = trim((string)($post['inicio'] ?? date('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
			$inicio = date('Y-m-d');
		}
		$mods = self::modulosDoPost($produto, $post, $plano instanceof PlanosAssinatura ? $plano : null);
		$nome = $plano instanceof PlanosAssinatura ? (string)$plano->nome : (ProductModules::slugParaLabel($produto).' personalizado');

		$c = new SaasContrato();
		$c->id_admin = $idAdmin;
		$c->plan_id = $plano instanceof PlanosAssinatura ? (int)$plano->id : null;
		$c->produto_slug = $produto;
		$c->plano_nome = $nome;
		$c->modulos_json = json_encode($mods, JSON_UNESCAPED_UNICODE);
		$c->valor_parcela = $valor;
		$c->qtd_parcelas = $qtd;
		$c->duracao_meses = $duracao;
		$c->ciclo = $ciclo;
		$c->inicio = $inicio;
		$c->fim = date('Y-m-d', strtotime($inicio.' +'.$duracao.' months'));
		$c->status = 'vigente';
		$c->recorrente = 0;
		$c->renovado_de_id = !empty($post['renovado_de_id']) ? (int)$post['renovado_de_id'] : null;
		if (!$c->cadastrar()) {
			return ['ok' => false, 'message' => 'Não foi possível gravar o contrato.'];
		}
		self::gerarParcelas($c, $escola);
		self::sincronizarProdutos($idAdmin);
		self::gravarHtml($c, $escola);
		return ['ok' => true, 'message' => 'Contrato gravado. O preço fica neste contrato.', 'contrato' => self::formatar($c)];
	}

	/** @return array{ok:bool,message:string,contrato?:array,valor_catalogo?:float,valor_atual?:float} */
	public static function prepararRenovacao(int $id): array {
		$atual = SaasContrato::getById($id);
		if (!$atual instanceof SaasContrato || $atual->status !== 'vigente') {
			return ['ok' => false, 'message' => 'Contrato vigente não encontrado.'];
		}
		$catalogo = 0.0;
		if ((int)$atual->plan_id > 0) {
			$plano = PlanosAssinatura::getById((int)$atual->plan_id);
			if ($plano instanceof PlanosAssinatura) {
				$catalogo = $plano->valorSugerido();
			}
		}
		return [
			'ok' => true,
			'message' => 'Escolha o valor da renovação.',
			'valor_atual' => round((float)$atual->valor_parcela, 2),
			'valor_catalogo' => $catalogo,
			'contrato' => self::formatar($atual),
		];
	}

	/** @return array{ok:bool,message:string} */
	public static function renovar(int $id, array $post): array {
		$atual = SaasContrato::getById($id);
		if (!$atual instanceof SaasContrato || $atual->status !== 'vigente') {
			return ['ok' => false, 'message' => 'Contrato vigente não encontrado.'];
		}
		$post['produto_slug'] = (string)$atual->produto_slug;
		if (empty($post['plan_id'])) {
			$post['plan_id'] = (int)$atual->plan_id;
		}
		$post['renovado_de_id'] = (int)$atual->id;
		if (empty($post['inicio'])) {
			$fim = trim((string)($atual->fim ?? ''));
			$post['inicio'] = ($fim !== '' && $fim >= date('Y-m-d')) ? $fim : date('Y-m-d');
		}
		$novo = self::criar((int)$atual->id_admin, $post);
		if (!$novo['ok']) {
			return $novo;
		}
		$atual->status = 'encerrado';
		$atual->fim = date('Y-m-d');
		$atual->atualizar();
		self::sincronizarProdutos((int)$atual->id_admin);
		return ['ok' => true, 'message' => 'Contrato renovado. O anterior permanece com o preço antigo no histórico.'];
	}

	public static function sincronizarProdutos(int $idAdmin): void {
		if (!SaasContrato::tabelaExiste()) {
			return;
		}
		$slugs = [];
		foreach (SaasContrato::vigentesDoCliente($idAdmin) as $c) {
			$prod = trim((string)($c->produto_slug ?? ''));
			if ($prod !== '' && $prod !== 'legado' && isset(ProductModules::getCatalog()[$prod])) {
				$slugs[$prod] = true;
			}
			if ($prod === 'legado' || $prod === '') {
				foreach ($c->modulos() as $m) {
					if (isset(ProductModules::getCatalog()[$m])) {
						$slugs[$m] = true;
					}
				}
			}
		}
		$json = empty($slugs) ? null : json_encode(array_keys($slugs), JSON_UNESCAPED_UNICODE);
		(new Database('clientes_assinantes'))->update('id = '.$idAdmin, [
			'modulos_liberados' => $json,
		]);
		ModuleGateHelper::limparCache($idAdmin);
	}

	/** Garante parcela do mês nos contratos recorrentes (legado) e fatura de cada parcela em aberto. */
	public static function garantirFaturas(int $idAdmin): void {
		if (!SaasContrato::tabelaExiste() || !SaasFatura::tabelaExiste()) {
			return;
		}
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return;
		}
		foreach (SaasContrato::vigentesDoCliente($idAdmin) as $c) {
			if ((int)$c->recorrente === 1) {
				self::garantirParcelaRecorrente($c, $escola);
			}
			self::faturarParcelasAbertas($c, $escola);
		}
	}

	private static function garantirParcelaRecorrente(SaasContrato $c, ClientesAssinantes $escola): void {
		$comp = date('Y-m');
		$row = (new Database('saas_contrato_parcelas'))->execute(
			'SELECT p.id FROM saas_contrato_parcelas p
			 LEFT JOIN saas_faturas f ON f.id = p.fatura_id
			 WHERE p.contrato_id = '.(int)$c->id.' AND (p.status = "aberta" OR f.competencia = "'.$comp.'")
			 LIMIT 1'
		)->fetch(\PDO::FETCH_ASSOC);
		if (!empty($row)) {
			return;
		}
		$dia = max(1, min(28, (int)($escola->dia_vencimento_assinatura ?? 10)));
		$venc = date('Y-m-').str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
		$n = (new Database('saas_contrato_parcelas'))->execute(
			'SELECT COALESCE(MAX(numero),0)+1 AS n FROM saas_contrato_parcelas WHERE contrato_id = '.(int)$c->id
		)->fetch(\PDO::FETCH_ASSOC);
		$num = (int)($n['n'] ?? 1);
		(new Database('saas_contrato_parcelas'))->insert([
			'contrato_id' => (int)$c->id,
			'numero'      => $num,
			'valor'       => round((float)$c->valor_parcela, 2),
			'vencimento'  => $venc,
			'status'      => 'aberta',
			'fatura_id'   => null,
		]);
	}

	private static function faturarParcelasAbertas(SaasContrato $c, ClientesAssinantes $escola): void {
		$rs = (new Database('saas_contrato_parcelas'))->select(
			'contrato_id = '.(int)$c->id.' AND status = "aberta" AND (fatura_id IS NULL OR fatura_id = 0)',
			'numero ASC'
		);
		while ($p = $rs->fetch(\PDO::FETCH_ASSOC)) {
			$venc = (string)$p['vencimento'];
			$comp = substr($venc, 0, 7);
			$fat = new SaasFatura();
			$fat->id_admin = (int)$escola->id;
			$fat->plan_id = $c->plan_id ? (int)$c->plan_id : null;
			$fat->contrato_id = (int)$c->id;
			$fat->numero_parcela = (int)$p['numero'];
			$fat->competencia = $comp;
			$fat->valor = round((float)$p['valor'], 2);
			$fat->vencimento = $venc;
			$fat->status = 'aberta';
			if (!$fat->cadastrar()) {
				continue;
			}
			(new Database('saas_contrato_parcelas'))->update('id = '.(int)$p['id'], [
				'fatura_id' => (int)$fat->id,
			]);
		}
	}

	private static function gerarParcelas(SaasContrato $c, ClientesAssinantes $escola): void {
		$qtd = max(1, (int)$c->qtd_parcelas);
		$dia = (int)substr((string)$c->inicio, 8, 2);
		if ($dia < 1 || $dia > 28) {
			$dia = max(1, min(28, (int)($escola->dia_vencimento_assinatura ?? 10)));
		}
		$base = new \DateTimeImmutable(substr((string)$c->inicio, 0, 7).'-'.str_pad((string)$dia, 2, '0', STR_PAD_LEFT));
		for ($i = 1; $i <= $qtd; $i++) {
			$venc = $base->modify('+'.($i - 1).' months')->format('Y-m-d');
			(new Database('saas_contrato_parcelas'))->insert([
				'contrato_id' => (int)$c->id,
				'numero'      => $i,
				'valor'       => round((float)$c->valor_parcela, 2),
				'vencimento'  => $venc,
				'status'      => 'aberta',
				'fatura_id'   => null,
			]);
		}
		self::faturarParcelasAbertas($c, $escola);
	}

	public static function htmlVisualizacao(int $id): string {
		$c = SaasContrato::getById($id);
		if (!$c instanceof SaasContrato) {
			return '<p>Contrato não encontrado.</p>';
		}
		$snap = trim((string)($c->html_snapshot ?? ''));
		if ($snap !== '') {
			return $snap;
		}
		$escola = \App\Model\Entity\ClientesAssinantes::getEscolaById((int)$c->id_admin);
		if (!$escola instanceof \App\Model\Entity\ClientesAssinantes) {
			return '<p>Cliente não encontrado.</p>';
		}
		$vars = \App\Common\Helpers\SaasContratoVariaveisBuilder::montarFromEscola($escola);
		return \App\Common\Helpers\SaasContratoTemplateHelper::render($vars);
	}

	private static function dataBr(string $iso): string {
		$iso = trim($iso);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
			return $iso;
		}
		$txt = $m[3].'/'.$m[2].'/'.$m[1];
		if (preg_match('/(\d{2}:\d{2})/', $iso, $h)) {
			$txt .= ' '.$h[1];
		}
		return $txt;
	}

	private static function gravarHtml(SaasContrato $c, ClientesAssinantes $escola): void {
		try {
			$vars = SaasContratoVariaveisBuilder::montarFromEscola($escola);
			$html = SaasContratoTemplateHelper::render($vars);
			$c->html_snapshot = $html;
			$c->atualizar();
		} catch (\Throwable $e) {
		}
	}

	/** @return string[] */
	private static function modulosDoPost(string $produto, array $post, ?PlanosAssinatura $plano): array {
		if (!ProductModules::ehModular($produto)) {
			return [];
		}
		$raw = $post['modulos_json'] ?? null;
		if ($raw === null && $plano instanceof PlanosAssinatura) {
			return $plano->getSlugs();
		}
		$arr = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
		$permitidos = array_flip(ProdutoModulo::slugs($produto));
		$out = [];
		foreach ($arr as $s) {
			$s = (string)$s;
			if ($permitidos === [] || isset($permitidos[$s])) {
				$out[] = $s;
			}
		}
		return array_values(array_unique($out));
	}

	private static function parseValor($raw): float {
		if (is_numeric($raw)) {
			return max(0, round((float)$raw, 2));
		}
		$s = trim((string)$raw);
		$s = str_replace(['R$', ' '], '', $s);
		if (strpos($s, ',') !== false) {
			$s = str_replace('.', '', $s);
			$s = str_replace(',', '.', $s);
		}
		return max(0, round((float)$s, 2));
	}

	public static function formatar(SaasContrato $c): array {
		$catalogo = null;
		if ((int)$c->plan_id > 0) {
			$plano = PlanosAssinatura::getById((int)$c->plan_id);
			if ($plano instanceof PlanosAssinatura) {
				$catalogo = $plano->valorSugerido();
			}
		}
		$label = $c->produto_slug === 'legado'
			? 'Legado'
			: (ProductModules::slugParaLabel((string)$c->produto_slug) ?: (string)$c->produto_slug);
		return [
			'id'             => (int)$c->id,
			'plan_id'        => $c->plan_id ? (int)$c->plan_id : null,
			'produto_slug'   => (string)$c->produto_slug,
			'produto_label'  => $label,
			'plano_nome'     => (string)$c->plano_nome,
			'modulos'        => $c->modulos(),
			'valor_parcela'  => round((float)$c->valor_parcela, 2),
			'valor_br'       => number_format((float)$c->valor_parcela, 2, ',', '.'),
			'valor_catalogo' => $catalogo,
			'qtd_parcelas'   => (int)$c->qtd_parcelas,
			'duracao_meses'  => (int)$c->duracao_meses,
			'ciclo'          => (string)$c->ciclo,
			'inicio'         => (string)$c->inicio,
			'fim'            => (string)($c->fim ?? ''),
			'inicio_br'      => self::dataBr((string)$c->inicio),
			'fim_br'         => self::dataBr((string)($c->fim ?? '')),
			'status'         => (string)$c->status,
			'recorrente'     => (int)$c->recorrente ? 1 : 0,
			'aceito_em'      => (string)($c->aceito_em ?? ''),
			'aceito_em_br'   => self::dataBr((string)($c->aceito_em ?? '')),
			'aceito_nome'    => (string)($c->aceito_nome ?? ''),
			'aceito'         => trim((string)($c->aceito_em ?? '')) !== '',
		];
	}

	/** @return array{ok:bool,message:string} */
	public static function aceitar(int $idAdmin, int $usuarioId, string $nome, string $cpf, string $ip, bool $leu): array {
		if (!$leu) {
			return ['ok' => false, 'message' => 'Leia o contrato até o final antes de aceitar.'];
		}
		$cpf = preg_replace('/\D+/', '', $cpf);
		if (strlen($cpf) !== 11) {
			return ['ok' => false, 'message' => 'Informe o CPF de quem assina (11 dígitos).'];
		}
		$vigentes = SaasContrato::vigentesDoCliente($idAdmin);
		if ($vigentes === []) {
			return ['ok' => false, 'message' => 'Não há contrato vigente para aceitar.'];
		}
		$pendentes = 0;
		foreach ($vigentes as $c) {
			if (trim((string)($c->aceito_em ?? '')) !== '') {
				continue;
			}
			$pendentes++;
			if (trim((string)($c->html_snapshot ?? '')) === '') {
				$escola = \App\Model\Entity\ClientesAssinantes::getEscolaById($idAdmin);
				if ($escola instanceof \App\Model\Entity\ClientesAssinantes) {
					self::gravarHtml($c, $escola);
					$c = SaasContrato::getById((int)$c->id) ?: $c;
				}
			}
			$html = (string)($c->html_snapshot ?? '');
			if ($html === '') {
				$html = self::htmlVisualizacao((int)$c->id);
			}
			if ($html === '' || strpos($html, 'não encontrado') !== false) {
				return ['ok' => false, 'message' => 'O texto do contrato ainda não está disponível.'];
			}
			$c->registrarAceite($usuarioId, $nome, $cpf, $ip, hash('sha256', $html));
		}
		if ($pendentes === 0) {
			return ['ok' => true, 'message' => 'Este contrato já foi aceito.'];
		}
		return ['ok' => true, 'message' => 'Contrato aceito. O texto lido ficou registrado.'];
	}

	/** @return array{ok:bool,message:string} */
	public static function cancelar(int $id): array {
		$c = SaasContrato::getById($id);
		if (!$c instanceof SaasContrato) {
			return ['ok' => false, 'message' => 'Contrato não encontrado.'];
		}
		if ($c->status !== 'vigente') {
			return ['ok' => false, 'message' => 'Só um contrato vigente pode ser cancelado.'];
		}
		$c->status = 'cancelado';
		$c->fim = date('Y-m-d');
		$c->atualizar();
		try {
			(new Database('saas_contrato_parcelas'))->execute(
				'UPDATE saas_contrato_parcelas SET status = "cancelada" WHERE contrato_id = '.(int)$c->id.' AND status = "aberta"'
			);
			(new Database('saas_faturas'))->execute(
				'UPDATE saas_faturas SET status = "cancelada" WHERE contrato_id = '.(int)$c->id.' AND status IN ("aberta","vencida")'
			);
		} catch (\Throwable $e) {
		}
		self::sincronizarProdutos((int)$c->id_admin);
		return ['ok' => true, 'message' => 'Contrato cancelado. Parcelas em aberto foram encerradas.'];
	}

	/**
	 * @param array{q?:string,status?:string,produto?:string} $filtros
	 * @return array<int,array>
	 */
	public static function listarFiltrado(array $filtros): array {
		if (!SaasContrato::tabelaExiste()) {
			return [];
		}
		$where = ['1=1'];
		$status = trim((string)($filtros['status'] ?? ''));
		if (in_array($status, ['vigente', 'encerrado', 'cancelado'], true)) {
			$where[] = 'c.status = "'.$status.'"';
		}
		$produto = preg_replace('/[^a-z0-9_]/', '', (string)($filtros['produto'] ?? ''));
		if ($produto !== '') {
			$where[] = 'c.produto_slug = "'.$produto.'"';
		}
		$aceite = trim((string)($filtros['aceite'] ?? ''));
		if ($aceite === 'aceito') {
			$where[] = 'c.aceito_em IS NOT NULL';
		} elseif ($aceite === 'pendente') {
			$where[] = 'c.aceito_em IS NULL AND c.status = "vigente"';
		}
		$q = trim((string)($filtros['q'] ?? ''));
		if ($q !== '') {
			$like = addslashes('%'.$q.'%');
			$where[] = '(e.nome LIKE "'.$like.'" OR c.plano_nome LIKE "'.$like.'" OR c.produto_slug LIKE "'.$like.'")';
		}
		$sql = 'SELECT c.*, e.nome AS cliente_nome FROM saas_contratos c
			LEFT JOIN clientes_assinantes e ON e.id = c.id_admin
			WHERE '.implode(' AND ', $where).' ORDER BY c.id DESC LIMIT 300';
		$out = [];
		$rows = (new Database('saas_contratos'))->execute($sql);
		while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
			$ob = new SaasContrato();
			foreach ($row as $k => $v) {
				if (property_exists($ob, $k)) {
					$ob->$k = $v;
				}
			}
			$fmt = self::formatar($ob);
			$fmt['cliente_nome'] = (string)($row['cliente_nome'] ?? '');
			$fmt['id_admin'] = (int)($row['id_admin'] ?? 0);
			$out[] = $fmt;
		}
		return $out;
	}

	/** @return array<int,array> */
	public static function listar(int $idAdmin): array {
		if (!SaasContrato::tabelaExiste()) {
			return [];
		}
		$out = [];
		$rs = SaasContrato::get('id_admin = '.$idAdmin, 'id DESC');
		while ($c = $rs->fetchObject(SaasContrato::class)) {
			$out[] = self::formatar($c);
		}
		return $out;
	}
}
