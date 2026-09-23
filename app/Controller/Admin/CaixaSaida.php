<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\Caixa as EntityCaixa;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\NumeroHelper;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\FinanceiroAlunoHelper;

class CaixaSaida extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/caixa/saida',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Saída',$content,'Financeiro');
	}

	private static function getDataItens($request,&$obPagination){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];
		
		//PAGINA ATUAL
		$queryParams = $request->getPostVars();
		$paginaAtual = $queryParams['page'] ?? 1;

		$filtro = $queryParams['filtro'] ?? null;

		if ($filtro == 'hoje') {
			$vencimentoSql = 'AND vencimento = CURDATE()';

		} elseif ($filtro == 'semana') {
			$vencimentoSql = "AND WEEK(CURRENT_TIMESTAMP) = WEEK(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)";

		} elseif ($filtro == 'mes') {
			$vencimentoSql = "AND MONTH(CURRENT_DATE) = MONTH(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)";

} elseif ($filtro == 'atraso') { // Corrigido "atrazo" para "atraso"
$vencimentoSql = "AND vencimento < CURDATE()";

} else {
	$vencimentoSql = 'AND vencimento = CURDATE()';
}

// Corrigido a construção da cláusula WHERE
$where = 'id_admin = "' . $id_admin . '" AND '.FinanceiroAlunoHelper::sqlTituloAberto('status').' AND tipo_transacao = "Saida" ' . $vencimentoSql;

$itens = '';

// QUANTIDADE TOTAL DE REGISTROS
$quantidadeTotal = EntityCaixa::getCaixa($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd;


		//INSTANCIA DE PAGINAÇÃO
$limite = (int)(getenv('PAGINATION_LIMIT') ?: 10);
$obPagination = new Pagination($quantidadeTotal,$paginaAtual,$limite);

		//RESULTADOS DA PAGINA
$results = EntityCaixa::getCaixa($where, 'id ASC', $obPagination->getLimit());

		//REDERIZA O ITEM
while ($obDados = $results->fetchObject(EntityCaixa::class)) {

   if (FinanceiroAlunoHelper::tituloAberto($obDados->status)) {
    $status = 'Em aberto';
} else if (FinanceiroAlunoHelper::tituloPago($obDados->status)) {
    $status = 'Pago';
} else {
    $status = (string)($obDados->status ?? '');
} 

$itens .= '<tr>
<td>'.$obDados->descricao.'</td>
<td>'.DateTimeHelper::databr($obDados->vencimento).'</td>
<td>R$ <span class="mascara-dinheiro">'.$obDados->valor.'</span></td>
<td>R$ <span class="mascara-dinheiro">'.$obDados->valor_pago.'</span></td>
<td>'.$obDados->data_pagamento.'</td>
<td>'.$status.'</td>

<td>
<a class="btn btn-secondary" href="#" onclick="list_itens('.$obDados->id.', \'editar\')"><i class="far fa-edit fa-lg"></i> Editar</a>
</td>

</tr>';

}


$table = '<div class="card-body">
<div class="table-responsive">
<table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
<thead>
<tr>
<th>Titulo</th>
<th>Vencimento</th>
<th>Valor</th>
<th>Valor Pago</th>
<th>Data Pgto</th>
<th>Status</th>
<th>Editar</th>
</tr>
</thead>
<tbody>'.$itens.'</tbody>
</table>
</div>
<script src="'.URL.'/resources/js/js_mascara.js"></script>
</div>';

		//RETORNA
return $table;
}

public static function getInfo($request){


	//CONTEÚDO 
	$conteudo = [
		'itens' => self::getDataItens($request,$obPagination),
		'pagination' => parent::getPagination($request,$obPagination)
	];

	return parent::jsonLista($conteudo);

}

private static function getForm($request) {
    $postVars = $request->getPostVars();
    $habilitado = '';
    $desconto = 0;
    $valorComDesconto = 0;
    $valorPagar = 0;
    $vencimento = '';
    $valor = 0;
    $vencido='';
    $edicao = '';
    $data_vencimento_mostrar ='';
    $total_pagar='';

    $inputValorPagar = '<input id="valor_pagar" name="valor_pagar" class="form-control my-1" style="width: 100%" type="text" value="" oninput="valorPagar()" required>';

    $dados = [];
    if (isset($postVars['funcao']) && $postVars['funcao'] == 'editar') {
        $id = (int)($postVars['id'] ?? 0);
        $id_admin = parent::getIdAdminInt();
        if (!TenantHelper::pertenceCaixa($id, $id_admin)) {
            return json_encode(['erro' => 'Registro não encontrado.']);
        }
        $dados = (array) EntityCaixa::getCaixaById($id);

        //$obMatricula = (array) EntityMatri::getMatriculaById($dados['id_ref']);

        // DADOS DO USUARIO
        $nivel = parent::getIdAdmin()['usuario']['nivel'];
        $habilitado = ($nivel != 'Diretor') ? 'readonly' : '';

        $edicao = 'readonly';

        $dias = DateTimeHelper::subtrairDatas($dados['vencimento'], DateTimeHelper::hoje())->d;

        if ($dados['vencimento'] > DateTimeHelper::hoje()) {
            $vencido = 'vence em';
        } else {
            $vencido = 'vencido há';
        }

        $valor = $dados['valor'];
        $valorPagar = $dados['valor'];

        $vencimento = DateTimeHelper::databr($dados['vencimento']);

        $data_vencimento_mostrar =
        '<li class="list-group-item d-flex justify-content-between lh-sm">
        <div>
        <h6 class="my-0">Data de vencimento</h6>
        <small class="text-muted">' . $vencimento . '</small>
        </div>
        <span class="text-muted">' . $vencido . ' ' . ($dias ?? 0) . ' dias</span>
        </li>';

        $inputValorPagar = '
        <small class="text-muted">' .'R$ '. NumeroHelper::moedaBr(@$dados['valor']) . '</small>
        <input value="' . $valorPagar . '" type="hidden" id="valor_pagar" name="valor_pagar">';

        $total_pagar = '<li class="list-group-item d-flex justify-content-between lh-sm">
        <div>
        <h6 class="my-0">Desconto pontualidade</h6>
        <small class="text-muted">R$ ' . NumeroHelper::moedaBr($valorComDesconto) . '</small>
        </div>
        <span class="text-muted">Valor do desconto: R$ ' . NumeroHelper::moedaBr($desconto) . '</span>
        </li>
        <li class="list-group-item d-flex justify-content-between">
        <span>Total a pagar</span>
        <strong>R$ ' . NumeroHelper::moedaBr($valorPagar) . '</strong>
        </li>';
    }

    $form = '<form id="form" method="post">
    <div class="modal-header">
    <h1 class="modal-title fs-5" id="exampleModalLabel">Titulo a Pagar</h1>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
    <div id="response"></div>
    <ul class="list-group mb-3 col-md-12">
    <li class="list-group-item justify-content-between lh-sm">
    <div>
    <h6 class="my-0">Descrição do titulo</h6>
    <input class="form-control my-1" '.$edicao.' name="titulo" type="text" value="' . ($dados['descricao'] ?? '') . '">
    </div>
    </li>
    <li class="list-group-item d-flex justify-content-between lh-sm">
    <div>
    <h6 class="my-0">Valor Original</h6>
    '.$inputValorPagar.'
    </div>
    <span class="text-muted"></span>
    </li>
    '.$data_vencimento_mostrar.'
    
    '.$total_pagar.'
    </ul>

    

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
    <input type="datetime-local" name="data_pagamento" ' . $habilitado . ' value="' . DateTimeHelper::agora() . '" class="form-control">
    </div> 
    <div class="form-group col-md-6">
    <label>Valor pago</label>
    <input type="text" id="valor_recebido" name="valor_recebido" class="form-control" oninput="calcularTroco()" required>
    </div>
    <div class="form-group col-md-6">
    <label>Troco</label>
    <input type="text" id="troco" readonly class="form-control">
    </div>
    </div>
    </div>
    <div class="modal-footer">
    <input value="' . ($dados['id'] ?? '') . '" type="hidden" name="id">
    <button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
    <button type="submit" class="btn btn-primary">Salvar</button>
    </div>
    </form>';

    return $form;
}




public static function getNewCaixa($request){

	$form = self::getForm($request);
	return json_encode($form);
}

public static function registrarPagamento($request){

  $postVars = $request->getPostVars();

  $resposta = [
    "filtro" => 'hoje'
  ];

  $titulo = filter_var($postVars['titulo'] ?? '', FILTER_SANITIZE_STRING);

  $valor_pagar = str_replace(',', '.', $postVars['valor_pagar']);
$valor_pagar = filter_var($valor_pagar, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$valor_pagar = floatval($valor_pagar);

$valor_recebido = str_replace(',', '.', $postVars['valor_recebido']);
$valor_recebido = filter_var($valor_recebido, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$valor_recebido = floatval($valor_recebido);


  if($valor_recebido < $valor_pagar){
    $resposta ["erro"] = 'Valor recebido é menor que o valor a receber.';
    return json_encode($resposta);
}

if($postVars['tipo_pagamento'] == ''){
    $resposta ["erro"] = 'Selecione uma forma de pagamento.';
    return json_encode($resposta);
}

if($titulo == ''){
    $resposta ["erro"] = 'Insira uma descrição para a movimentação.';
    return json_encode($resposta);
}

//DADOS DO USUARIO
$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

if($postVars['id'] == ''){

	//  INSERIR
  $obCaixa = new EntityCaixa;
  $obCaixa->id_admin = $id_admin;
  $obCaixa->descricao = $titulo;
  $obCaixa->valor = $valor_pagar;
  $obCaixa->valor_pago = $valor_pagar;
  $obCaixa->vencimento = $postVars['data_pagamento'] ?? '';
  $obCaixa->data_pagamento = $postVars['data_pagamento'] ?? '';
  $obCaixa->tipo_pagamento = $postVars['tipo_pagamento'] ?? '';
  $obCaixa->tipo_transacao = 'Saida';
  $obCaixa->referencia = 'Saída avulsa';
  $obCaixa->id_ref = 0;
  $obCaixa->txt_id = '';
  $obCaixa->pix_copia_cola = '';
  $obCaixa->nosso_numero = '';
  $obCaixa->status = FinanceiroAlunoHelper::STATUS_PAGO;
  $obCaixa->lancarMovimentacao();
  

} else {

    $caixaId = (int)($postVars['id'] ?? 0);
    if (!TenantHelper::pertenceCaixa($caixaId, (int)$id_admin)) {
      $resposta['erro'] = 'Registro não encontrado.';
      return json_encode($resposta);
    }

    // EDIÇÃO
  $obCaixa = new EntityCaixa;
  $obCaixa->id = $caixaId;
  $obCaixa->valor_pago = $valor_pagar;
  $obCaixa->data_pagamento = $postVars['data_pagamento'] ?? '';
  $obCaixa->tipo_pagamento = $postVars['tipo_pagamento'] ?? '';
  $obCaixa->status = FinanceiroAlunoHelper::STATUS_PAGO;
  $obCaixa->atualizar();

}

if(!$obCaixa){
    $resposta ["erro"] = 'Erro ao registrar o pagamento';
  }

   return json_encode($resposta);

}


}