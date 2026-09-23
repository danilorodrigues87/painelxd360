<?php 

namespace App\Controller\Admin;

use \App\Model\Entity\CaixaCarrinho as EntityCaixaCarrinho;
use \App\Model\Entity\Caixa as EntityCaixa;
use \App\Model\Entity\Matriculas as EntityMatri;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\NumeroHelper;
use \App\Common\Helpers\FinanceiroAlunoHelper;
use \App\Common\Helpers\EncargosContratoHelper;

class CaixaCarrinho extends Page{

	/**
	 * Valor a pagar de um título (pontualidade + multa/juros opcionais).
	 * @return array{face:float,valor:float,enc:array}
	 */
	private static function resolverValoresTitulo(EntityCaixa $obCaixa, bool $cobrarEncargos): array {
		$flagPont = 0;
		$idRef = (int)($obCaixa->id_ref ?? 0);
		if ($idRef > 0) {
			$mat = EntityMatri::getMatriculaById($idRef);
			if ($mat) {
				$flagPont = (int)($mat->desconto_pontualidade ?? 0);
			}
		}
		$pont = FinanceiroAlunoHelper::calcularPontualidade(
			(float)$obCaixa->valor,
			(string)($obCaixa->vencimento ?? ''),
			$flagPont
		);
		$face = (float)$pont['valor_pagar'];
		$enc = EncargosContratoHelper::calcularAtraso(
			$face,
			(string)($obCaixa->vencimento ?? ''),
			$idRef > 0 ? EncargosContratoHelper::parametrosPorMatricula($idRef) : EncargosContratoHelper::defaults()
		);
		if ($idRef <= 0 || ($obCaixa->vencimento ?? '') === '' || ($obCaixa->vencimento ?? '') > DateTimeHelper::hoje()) {
			$enc['elegivel_encargos'] = false;
			$enc['total_com_encargos'] = $face;
			$enc['total_sem_encargos'] = $face;
		}
		$valor = ($cobrarEncargos && !empty($enc['elegivel_encargos']))
			? (float)$enc['total_com_encargos']
			: $face;

		return [
			'face'  => round($face, 2),
			'valor' => round($valor, 2),
			'enc'   => $enc,
		];
	}

	private static function htmlEncargosLinhaCarrinho(int $idCaixa, array $enc): string {
		if (empty($enc['elegivel_encargos'])) {
			return '';
		}
		$multa = NumeroHelper::moedaBr($enc['multa'] ?? 0);
		$juros = NumeroHelper::moedaBr($enc['juros'] ?? 0);
		return '
			<div class="form-check mt-1 mb-0">
				<input class="form-check-input cobrar-encargos-item" type="checkbox"
					id="enc_carrinho_'.$idCaixa.'" name="cobrar_encargos['.$idCaixa.']" value="1" checked>
				<label class="form-check-label small" for="enc_carrinho_'.$idCaixa.'">
					Cobrar multa (R$ '.$multa.') e juros (R$ '.$juros.')
				</label>
			</div>
			<p class="small text-muted mb-0 mt-1">Desmarque para perdoar multa e juros desta parcela.</p>';
	}

	//RESUMO DO CARRINHO (USADO PELO CARD FLUTUANTE)
	public static function getResumo($request){

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$results = EntityCaixaCarrinho::getCaixaCarrinho(
			'id_admin = '.(int)$id_admin.' AND id_usuario = '.(int)$id_usuario,
			'id DESC'
		);

		$itensHtml = '';
		$total = 0;
		$qtd   = 0;

		while ($obItem = $results->fetchObject(EntityCaixaCarrinho::class)) {
			$qtd++;
			$valorExibir = (float)$obItem->valor;
			$badgeEnc = '';

			if ($obItem->tipo === 'titulo' && (int)$obItem->referencia_id > 0) {
				$obCaixa = EntityCaixa::getCaixaById((int)$obItem->referencia_id);
				if ($obCaixa instanceof EntityCaixa) {
					$vals = self::resolverValoresTitulo($obCaixa, true);
					$valorExibir = $vals['valor'];
					if (!empty($vals['enc']['elegivel_encargos'])) {
						$badgeEnc = '<br><small class="text-warning">incl. multa/juros</small>';
					}
				}
			}

			$total += $valorExibir;

			$tipo = ucfirst($obItem->tipo);

			$itensHtml .= '
			<li class="list-group-item d-flex justify-content-between align-items-center">
				<div>
					<strong>'.$obItem->descricao.'</strong><br>
					<small class="text-muted">'.$tipo.'</small>'.$badgeEnc.'
				</div>
				<div class="text-end">
					<span>R$ '.NumeroHelper::moedaBr($valorExibir).'</span><br>
					<button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removerItemCarrinho('.$obItem->id.')">
						&times; remover
					</button>
				</div>
			</li>';
		}

		if($qtd == 0){
			$itensHtml = '
			<li class="list-group-item">
				<small class="text-muted">Nenhum item no carrinho.</small>
			</li>';
		}

		$conteudo = [
			'qtd'        => $qtd,
			'total'      => NumeroHelper::moedaBr($total),
			'html_itens' => $itensHtml
		];

		return json_encode($conteudo);
	}

	//ADICIONA UM TÍTULO (LANÇAMENTO DO CAIXA) AO CARRINHO
	public static function addTitulo($request){

		$postVars = $request->getPostVars();
		$idCaixa  = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$resposta = [];

		if($idCaixa <= 0){
			$resposta['erro'] = 'Título inválido.';
			return json_encode($resposta);
		}

		$obCaixa = EntityCaixa::getCaixaById($idCaixa);

		if(!$obCaixa instanceof EntityCaixa){
			$resposta['erro'] = 'Título não encontrado.';
			return json_encode($resposta);
		}

		if((int)$obCaixa->id_admin !== (int)$id_admin){
			$resposta['erro'] = 'Título não encontrado.';
			return json_encode($resposta);
		}

		if (FinanceiroAlunoHelper::tituloPago($obCaixa->status)) {
			$resposta['erro'] = 'Este título já está pago.';
			return json_encode($resposta);
		}

		if (!FinanceiroAlunoHelper::tituloAberto($obCaixa->status)) {
			$resposta['erro'] = 'Este título não está em aberto.';
			return json_encode($resposta);
		}

		//VERIFICA SE JÁ ESTÁ NO CARRINHO
		$existe = EntityCaixaCarrinho::getCaixaCarrinho(
			'id_admin = '.(int)$id_admin.
			' AND id_usuario = '.(int)$id_usuario.
			' AND referencia_id = '.$idCaixa.
			' AND tipo = "titulo"'
		)->fetchObject(EntityCaixaCarrinho::class);

		
		if($existe instanceof EntityCaixaCarrinho){
			$resposta['erro'] = 'Este título já foi adicionado ao carrinho.';
			return json_encode($resposta);
		}

		$descricao = $obCaixa->descricao.' - Venc. '.DateTimeHelper::databr($obCaixa->vencimento);

		$flagPont = 0;
		$idRef = (int)($obCaixa->id_ref ?? 0);
		if ($idRef > 0) {
			$mat = EntityMatri::getMatriculaById($idRef);
			if ($mat) {
				$flagPont = $mat->desconto_pontualidade ?? 0;
			}
		}
		$pont = FinanceiroAlunoHelper::calcularPontualidade(
			(float)$obCaixa->valor,
			$obCaixa->vencimento ?? '',
			$flagPont
		);

		$vals = self::resolverValoresTitulo($obCaixa, true);
		$msgExtra = '';
		if (!empty($vals['enc']['elegivel_encargos'])) {
			$msgExtra = ' Multa/juros incluídos — ajuste no pagamento se quiser perdoar.';
		}

		$obCarrinho = new EntityCaixaCarrinho;
		$obCarrinho->id_admin      = $id_admin;
		$obCarrinho->id_usuario    = $id_usuario;
		$obCarrinho->referencia_id = $idCaixa;
		$obCarrinho->tipo          = 'titulo';
		$obCarrinho->descricao     = $descricao;
		$obCarrinho->valor         = (float)$pont['valor_pagar'];
		$obCarrinho->cadastrar();

		$resposta['sucesso'] = true;
		$resposta['mensagem'] = 'Título adicionado ao carrinho.'.$msgExtra;

		return json_encode($resposta);
	}

	//ADICIONA UM ITEM AVULSO (SERVIÇO/PRODUTO) AO CARRINHO
	public static function addAvulso($request){

		$postVars   = $request->getPostVars();
		$descricao  = trim($postVars['descricao'] ?? '');
		$valorBruto = $postVars['valor'] ?? '';

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$resposta = [];

		if($descricao == ''){
			$resposta['erro'] = 'Informe a descrição do serviço/produto.';
			return json_encode($resposta);
		}

		$valor = (float) NumeroHelper::removerFormatacaoNumero($valorBruto);

		if($valor <= 0){
			$resposta['erro'] = 'Informe um valor válido.';
			return json_encode($resposta);
		}

		$obCarrinho = new EntityCaixaCarrinho;
		$obCarrinho->id_admin      = $id_admin;
		$obCarrinho->id_usuario    = $id_usuario;
		$obCarrinho->referencia_id = 0;
		$obCarrinho->tipo          = 'servico';
		$obCarrinho->descricao     = $descricao;
		$obCarrinho->valor         = $valor;
		$obCarrinho->cadastrar();

		$resposta['sucesso'] = true;

		return json_encode($resposta);
	}

	//REMOVE ITEM DO CARRINHO
	public static function removerItem($request){

		$postVars = $request->getPostVars();
		$id       = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$resposta = [];

		if($id <= 0){
			$resposta['erro'] = 'Item inválido.';
			return json_encode($resposta);
		}

		EntityCaixaCarrinho::deleteById($id,$id_admin,$id_usuario);

		$resposta['sucesso'] = true;

		return json_encode($resposta);
	}

	//FORMULÁRIO DE PAGAMENTO DO CARRINHO
	public static function formPagamento($request){

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$results = EntityCaixaCarrinho::getCaixaCarrinho(
			'id_admin = '.(int)$id_admin.' AND id_usuario = '.(int)$id_usuario,
			'id DESC'
		);

		$itensHtml = '';
		$total = 0;
		$temEncargos = false;

		while ($obItem = $results->fetchObject(EntityCaixaCarrinho::class)) {
			if ($obItem->tipo === 'titulo' && (int)$obItem->referencia_id > 0) {
				$obCaixa = EntityCaixa::getCaixaById((int)$obItem->referencia_id);
				if (!$obCaixa instanceof EntityCaixa) {
					continue;
				}
				$vals = self::resolverValoresTitulo($obCaixa, true);
				$idCaixa = (int)$obCaixa->id;
				$total += $vals['valor'];
				if (!empty($vals['enc']['elegivel_encargos'])) {
					$temEncargos = true;
				}
				$encHtml = self::htmlEncargosLinhaCarrinho($idCaixa, $vals['enc']);
				$itensHtml .= '
			<li class="list-group-item carrinho-linha-item carrinho-linha-titulo" data-caixa-id="'.$idCaixa.'">
				<div class="d-flex justify-content-between align-items-start gap-2">
					<div class="flex-grow-1">
						<strong>'.$obItem->descricao.'</strong>
						<div class="small text-muted">Parcela: R$ '.NumeroHelper::moedaBr($vals['face']).'</div>
						'.$encHtml.'
					</div>
					<span class="carrinho-item-valor fw-semibold text-nowrap"
						data-valor="'.htmlspecialchars((string)$vals['valor'], ENT_QUOTES, 'UTF-8').'"
						data-sem-enc="'.htmlspecialchars((string)$vals['face'], ENT_QUOTES, 'UTF-8').'"
						data-com-enc="'.htmlspecialchars((string)($vals['enc']['total_com_encargos'] ?? $vals['face']), ENT_QUOTES, 'UTF-8').'">
						R$ '.NumeroHelper::moedaBr($vals['valor']).'
					</span>
				</div>
			</li>';
			} else {
				$valorItem = (float)$obItem->valor;
				$total += $valorItem;
				$itensHtml .= '
			<li class="list-group-item carrinho-linha-item d-flex justify-content-between align-items-center">
				<div>
					<strong>'.$obItem->descricao.'</strong><br>
					<small class="text-muted">'.ucfirst($obItem->tipo).'</small>
				</div>
				<span class="carrinho-item-valor fw-semibold"
					data-valor="'.htmlspecialchars((string)$valorItem, ENT_QUOTES, 'UTF-8').'"
					data-sem-enc="'.htmlspecialchars((string)$valorItem, ENT_QUOTES, 'UTF-8').'"
					data-com-enc="'.htmlspecialchars((string)$valorItem, ENT_QUOTES, 'UTF-8').'">
					R$ '.NumeroHelper::moedaBr($valorItem).'
				</span>
			</li>';
			}
		}

		if($itensHtml == ''){
			$itensHtml = '
			<li class="list-group-item">
				<small class="text-muted">Nenhum item no carrinho.</small>
			</li>';
		}

		$valorTotalBr = NumeroHelper::moedaBr($total);
		$dataPagamento = date('Y-m-d\TH:i');

		$blocoEncargosGlobal = '';
		if ($temEncargos) {
			$blocoEncargosGlobal = '
				<li class="list-group-item bg-light encargos-carrinho-global">
					<div class="form-check mb-0">
						<input class="form-check-input" type="checkbox" id="cobrar_encargos_todos" checked>
						<label class="form-check-label fw-semibold" for="cobrar_encargos_todos">
							Cobrar multa e juros em todas as parcelas em atraso
						</label>
					</div>
					<p class="small text-muted mb-0 mt-1">Desmarque para perdoar encargos de todas as parcelas de uma vez.</p>
				</li>';
		}

		$form = '
		<form id="form-carrinho" method="post">
			<div class="modal-header">
				<h1 class="modal-title fs-5" id="exampleModalLabel">Pagamento do carrinho</h1>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="response-carrinho"></div>
				<ul class="list-group mb-3 col-md-12">
					'.$blocoEncargosGlobal.$itensHtml.'
					<li class="list-group-item d-flex justify-content-between">
						<span>Total a pagar</span>
						<strong id="carrinho-total-pagar">R$ '.$valorTotalBr.'</strong>
					</li>
				</ul>

				<input value="'.$total.'" type="hidden" id="valor_pagar_total" name="valor_pagar_total">

				<div class="row">
					<div class="form-group col-md-6">
						<label>Forma de pagamento</label>
						<select name="tipo_pagamento" class="form-control">
							<option value="">Selecione o tipo</option>
							<option value="Dinheiro">Dinheiro</option>  
							<option value="Pix">Pix</option>    
							<option value="Cartão">Cartão</option>    
							<option value="Boleto">Boleto</option>                   
						</select>
					</div>

					<div class="form-group col-md-6">
						<label>Data de pagamento</label>
						<input type="datetime-local" name="data_pagamento" value="'.$dataPagamento.'" class="form-control">
					</div>

					<div class="form-group col-md-6">
						<label>Valor recebido</label>
						<input type="text" id="valor_recebido_carrinho" name="valor_recebido" class="form-control" oninput="calcularTrocoCarrinho()" required>
					</div>

					<div class="form-group col-md-6">
						<label>Troco</label>
						<input type="text" id="troco_carrinho" readonly class="form-control">
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" id="btn-fechar-carrinho" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn btn-primary">Finalizar pagamento</button>
			</div>
		</form>';

		return json_encode($form);
	}

	//FINALIZA O PAGAMENTO DO CARRINHO
	public static function finalizar($request){

		$postVars = $request->getPostVars();

		$resposta = [];

		$tipo_pagamento = $postVars['tipo_pagamento'] ?? '';
		$data_pagamento = $postVars['data_pagamento'] ?? '';
		$cobrarEncargosMap = is_array($postVars['cobrar_encargos'] ?? null) ? $postVars['cobrar_encargos'] : [];

		$valor_pagar_total = (float)($postVars['valor_pagar_total'] ?? 0);

		$valor_recebido = (float) NumeroHelper::removerFormatacaoNumero($postVars['valor_recebido'] ?? '0');

		if($valor_pagar_total <= 0){
			$resposta['erro'] = 'Carrinho vazio ou total inválido.';
			return json_encode($resposta);
		}

		if($valor_recebido < $valor_pagar_total){
			$resposta['erro'] = 'Valor recebido é menor que o total a receber.';
			return json_encode($resposta);
		}

		if($tipo_pagamento == ''){
			$resposta['erro'] = 'Selecione uma forma de pagamento.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		//BUSCA ITENS DO CARRINHO
		$results = EntityCaixaCarrinho::getCaixaCarrinho(
			'id_admin = '.(int)$id_admin.' AND id_usuario = '.(int)$id_usuario,
			'id ASC'
		);

		$totalCalculado = 0;
		$idsPagos = [];
		$pendentes = [];

		while ($obItem = $results->fetchObject(EntityCaixaCarrinho::class)) {

			if($obItem->tipo == 'titulo'){

				$obCaixa = EntityCaixa::getCaixaById($obItem->referencia_id);

				if(!$obCaixa instanceof EntityCaixa){
					continue;
				}

				if (FinanceiroAlunoHelper::tituloPago($obCaixa->status)) {
					continue;
				}

				$idCaixa = (int)$obCaixa->id;
				$cobrarEnc = !empty($cobrarEncargosMap[$idCaixa]) || !empty($cobrarEncargosMap[(string)$idCaixa]);
				$vals = self::resolverValoresTitulo($obCaixa, $cobrarEnc);
				$valorItem = (float)$vals['valor'];
				$totalCalculado += $valorItem;

				$pendentes[] = [
					'tipo' => 'titulo',
					'caixa_id' => $idCaixa,
					'valor' => $valorItem,
				];

			} else {

				$valorItem = (float)$obItem->valor;
				$totalCalculado += $valorItem;

				$pendentes[] = [
					'tipo' => 'servico',
					'descricao' => $obItem->descricao,
					'valor' => $valorItem,
				];
			}
		}

		if($totalCalculado <= 0){
			$resposta['erro'] = 'Nenhum item válido encontrado no carrinho.';
			return json_encode($resposta);
		}

		if (abs($totalCalculado - $valor_pagar_total) > 0.02) {
			$resposta['erro'] = 'Total do pagamento não confere. Reabra o carrinho e tente novamente.';
			return json_encode($resposta);
		}

		foreach ($pendentes as $item) {
			if ($item['tipo'] === 'titulo') {
				$obUpdate = new EntityCaixa;
				$obUpdate->id             = $item['caixa_id'];
				$obUpdate->valor_pago     = $item['valor'];
				$obUpdate->data_pagamento = $data_pagamento;
				$obUpdate->tipo_pagamento = $tipo_pagamento;
				$obUpdate->status         = FinanceiroAlunoHelper::STATUS_PAGO;
				$obUpdate->atualizar();
				$idsPagos[] = (int)$item['caixa_id'];
				continue;
			}

			$valorItem = (float)$item['valor'];
			$obCaixa = new EntityCaixa;
			$obCaixa->id_admin       = $id_admin;
			$obCaixa->descricao      = $item['descricao'];
			$obCaixa->valor          = $valorItem;
			$obCaixa->valor_pago     = $valorItem;
			$obCaixa->vencimento     = $data_pagamento;
			$obCaixa->data_pagamento = $data_pagamento;
			$obCaixa->tipo_pagamento = $tipo_pagamento;
			$obCaixa->tipo_transacao = 'Entrada';
			$obCaixa->referencia     = 'Venda/serviço avulso';
			$obCaixa->id_ref         = 0;
			$obCaixa->txt_id         = '';
			$obCaixa->pix_copia_cola = '';
			$obCaixa->nosso_numero   = '';
			$obCaixa->status         = FinanceiroAlunoHelper::STATUS_PAGO;
			$obCaixa->lancarMovimentacao();
			if (!empty($obCaixa->id)) {
				$idsPagos[] = (int)$obCaixa->id;
			}
		}

		//LIMPA O CARRINHO
		EntityCaixaCarrinho::clearByUser($id_admin,$id_usuario);

		$resposta['sucesso'] = true;
		$resposta['total']   = NumeroHelper::moedaBr($totalCalculado);
		$resposta['filtro']  = 'hoje';
		$resposta['recibo_ids'] = $idsPagos;

		return json_encode($resposta);
	}

}
