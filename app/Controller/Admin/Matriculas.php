<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\Matriculas as EntityMatri;
use \App\Model\Entity\User as EntityUser;
use \App\Model\Entity\Responsaveis as EntityRes;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Model\Entity\Caixa as EntityCaixa;
use \App\Model\Db\Pagination;
use \App\Model\Entity\EstadoCidades;
use \App\Session\User\Login as SessionUser;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\NumeroHelper;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\BrandingHelper;
use \App\Common\Helpers\ContratoTemplateHelper;
use \App\Common\Helpers\ContratoVariaveisBuilder;
use \App\Common\Helpers\MatriculaStatusHelper;
use \App\Common\Helpers\EncargosContratoHelper;
use \App\Common\Helpers\CrmPessoaHelper;
use \App\Model\Entity\ClientesAssinantes;


class Matriculas extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/matriculas/index',[]);

		//RETORNA A PÁGINA COMPLETA
    /**
     * TITULO DA PAGINA
     * CONTEUDO
     * CURRENTSESSION SESSÃO ATUAL
     * REQUEST SE NESCESSÁRIO
     */
		return parent::getPanel('Matriculas',$content,'pedagogico');
	}

	private static function getMatriculasItens($request,&$obPagination){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

    //PAGINA ATUAL
    $postVars = $request->getPostVars();
    $paginaAtual = $postVars['page'] ?? 1;

 $id_cliente = (isset($postVars['filtro']) && $postVars['filtro'] !== '' && $postVars['filtro'] !== null && (int)$postVars['filtro'] > 0)
	? (int)$postVars['filtro'] : 0;
	$busca = trim((string)($postVars['busca'] ?? ''));
	$statusFiltro = trim((string)($postVars['status'] ?? ''));

    $where = 'id_admin = '.(int)$id_admin;
	if ($id_cliente > 0) {
		$where .= ' AND id_aluno = '.$id_cliente;
	}
	$idsBusca = TenantHelper::idsAlunosPorBusca((int)$id_admin, $busca);
	if (is_array($idsBusca)) {
		if (!$idsBusca) {
			$where .= ' AND 1=0';
		} else {
			$where .= ' AND id_aluno IN ('.implode(',', $idsBusca).')';
		}
	}
	if ($statusFiltro === '0' || $statusFiltro === '1' || $statusFiltro === '3') {
		$where .= ' AND status = '.(int)$statusFiltro;
	}

		MatriculaStatusHelper::encerrarVencidasTenant((int)$id_admin);

    $itens = '';

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = (int)(EntityMatri::getMatriculas($where,null,null,'COUNT(*) as qtd')->fetchObject()->qtd ?? 0);

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,10);

		//RESULTADOS DA PAGINA
		$results = EntityMatri::getMatriculas($where, 'id DESC', $obPagination->getLimit()); 

		$temContrato = in_array('contratos', ModuleGateHelper::getSlugsEscola((int)$id_admin), true);

		//REDERIZA O ITEM
		while ($dados = $results->fetchObject(EntityMatri::class)) {
			$dadosUser = (array) EntityUser::getUserById($dados->id_aluno);
			$nomeAluno = htmlspecialchars((string)($dadosUser['nome'] ?? 'Aluno #'.$dados->id_aluno), ENT_QUOTES, 'UTF-8');
			$nomeTrilha = '—';
			try {
				$dadosTrilha = (array) EntityTrilhas::getTrilhaById($dados->id_trilha);
				$nomeTrilha = htmlspecialchars((string)($dadosTrilha['nome'] ?? '—'), ENT_QUOTES, 'UTF-8');
			} catch (\Throwable $e) {
				$nomeTrilha = '<span class="text-danger">Trilha indisponível</span>';
			}

            $disabled='';
			$btnEncerrar = '';

			$total = $dados->qtd_parcelas * $dados->valor;
			$statusMat = (int)$dados->status;
			$status = MatriculaStatusHelper::labelStatus($statusMat);
			$ehBolsista = EntityMatri::temColunaBolsista() && !empty($dados->bolsista);
			$colValor = $ehBolsista
				? '<span class="badge bg-info text-dark">Bolsista</span>'
				: '<span>R$ '.NumeroHelper::moedaBr($total).'</span>';
			if ($statusMat === MatriculaStatusHelper::STATUS_ANDAMENTO) {
				$btnEncerrar = '<li>
        <a class="dropdown-item" href="#" onclick="encerrar_contrato('.$dados->id.'); return false;">
        <i class="fa-regular fa-circle-check fa-lg"></i> Encerrar</a>
        </li>';
			} else {
                $disabled='disabled';
			}

			$btnRegularizar = '';
			if ($statusMat === MatriculaStatusHelper::STATUS_ENCERRADO
				&& MatriculaStatusHelper::contarTitulosAbertosMatricula((int)$dados->id, (int)$id_admin) > 0) {
				$btnRegularizar = '<li>
        <a class="dropdown-item" href="#" onclick="regularizar_financeiro('.$dados->id.'); return false;">
        <i class="fa-solid fa-scale-balanced fa-lg"></i> Regularizar financeiro</a>
        </li>';
			}

        $itens .= 
        '<tr>
        <td>'.$dados->id.'</td>
        <td>'.$nomeAluno.'</td>
        <td>'.$nomeTrilha.'</td>
        <td>'.$colValor.'</td>
        <td>'.$status.'</td>
        <td>
        <div class="dropdown">
        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="far fa-edit fa-lg"></i>
        </button>
        <ul class="dropdown-menu">
        '.($temContrato ? '<li>
        <a class="dropdown-item" target="_blank" href="'.URL.'/painel/matricula/'.$dados->id.'" >
        <i class="fa-regular fa-paste fa-lg"></i> Ver Contrato</a>
        </li>' : '').'
        <li>
        <a class="dropdown-item disabled" href="#" onclick="list_itens('.$dados->id.', \'editar\')">
        <i class="far fa-edit fa-lg"></i> Editar</a>
        </li>
        '.$btnEncerrar.'
        '.$btnRegularizar.'
        <li>
        <a class="dropdown-item '.$disabled.'" href="#" onclick="cancelar_contrato('.$dados->id.')" >
        <i class="fa-regular fa-rectangle-xmark fa-lg"></i> Cancelar</a>
        </li>
        </ul>
        </div>
        </td>
        </tr>';

     }

		if ($itens === '') {
			$itens = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma matrícula encontrada com esses filtros.</td></tr>';
		}

     $table = '<div class="card-body">
     <div class="table-responsive">
     <table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
     <thead>
     <tr>
     <th>Cód</th>
     <th>Aluno</th>
     <th>Trilha</th>
     <th>Valor Total</th>
     <th>Status</th>
     <th>Ações</th>
     </tr>
     </thead>
     <tbody>'.$itens.'</tbody>
     </table>
     </div>
     </div>';

		//RETORNA
     return $table;
  }

  public static function getInfo($request){


	//CONTEÚDO 
     $conteudo = [
      'itens' => self::getMatriculasItens($request,$obPagination),
      'pagination' => parent::getPagination($request,$obPagination)
   ];

   return parent::jsonLista($conteudo);

}

public static function getResponseble($request) {
 $postVars = $request->getPostVars();


 $id_aluno = isset($postVars['id_aluno']) ? $postVars['id_aluno'] : 0;
 $dadosAluno = (array)EntityUser::getUserById($id_aluno);
 $dadosResponsavel = (array)EntityRes::getResById($dadosAluno['id_responsavel']);

 $dadosRes = [
  'id' => $dadosResponsavel['id'] ?? '', 
  'nome' => $dadosResponsavel['nome'] ?? ''
];

return json_encode($dadosRes); 

}


private static function getForm($request) {

	$postVars = $request->getPostVars();
	$queryVars = $request->getQueryParams();
	$dados = [];

	$idAlunoPre = (int)($postVars['id_aluno'] ?? $postVars['aluno'] ?? 0);
	if ($idAlunoPre <= 0) {
		$idAlunoPre = (int)($queryVars['aluno'] ?? 0);
	}
	$idLeadPre = (int)($postVars['id_lead'] ?? $postVars['lead'] ?? 0);
	if ($idLeadPre <= 0) {
		$idLeadPre = (int)($queryVars['lead'] ?? 0);
	}

	if (($postVars['funcao'] ?? '') === 'editar') {
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = (int)parent::getIdAdmin()['usuario']['id_admin'];
		if ($id > 0 && TenantHelper::pertenceMatricula($id, $id_admin)) {
			$dados = (array) EntityMatri::getMatriculaById($id);
		}
	}

    //DADOS DO ADMIN
$id_admin = parent::getIdAdmin()['usuario']['id_admin'];


$resultsUser = EntityUser::getUser("nivel = 'Cliente' AND id_admin = '". $id_admin ."'", 'nome ASC');

    // Carrega o SELECT
$optSlqUsers = '<select class="form-control" onchange="selectAluno(this.value)" name="aluno"> 
                    <option value="0">Selecione um aluno</option> ';

while ($obAlunos = $resultsUser->fetchObject(EntityUser::class)) {
  $userSelected = '';
  if (isset($dados['id_aluno']) && $dados['id_aluno'] == $obAlunos->id) {
    $userSelected = 'selected';
  } elseif ($idAlunoPre > 0 && $idAlunoPre == (int)$obAlunos->id) {
    $userSelected = 'selected';
  }
  $optSlqUsers .= '
  <option ' . $userSelected . ' value="'.(int)$obAlunos->id.'">' . htmlspecialchars((string)$obAlunos->nome, ENT_QUOTES, 'UTF-8') . '</option>
  ';
}
$optSlqUsers .= '</select>';


$whereTrilhas = "id_admin = '". $id_admin ."'";
if (EntityTrilhas::temColunaAtivo()) {
  // Na edição, inclui a trilha atual mesmo se inativa
  $idTrilhaAtual = (int)($dados['id_trilha'] ?? 0);
  $whereTrilhas .= ' AND (ativo = 1'.($idTrilhaAtual > 0 ? ' OR id = '.$idTrilhaAtual : '').')';
}
$resultsTrilhas = EntityTrilhas::getTrilha($whereTrilhas,'nome ASC');

    // Carrega o SELECT
$optSlqTrilhas = '';

while ($obTrilhas = $resultsTrilhas->fetchObject(EntityTrilhas::class)) {
  $trilhaSelected = (isset($dados['id_trilha']) && $dados['id_trilha'] == $obTrilhas->id) ? 'selected' : '';
  $optSlqTrilhas .= '
  <option ' . $trilhaSelected . ' value="' . (int)$obTrilhas->id . '">' . htmlspecialchars((string)$obTrilhas->nome, ENT_QUOTES, 'UTF-8') . '</option>
  ';
}

$pp = date('m');
$ano = date('Y');

$form = '<form id="form" method="post">
<div class="modal-header">
<h1 class="modal-title fs-5" id="exampleModalLabel">Matricula Nº ' . (isset($dados['id']) ? $dados['id'] : "***") . '</h1>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div id="response"></div>

<div class="row">

<div class="form-group col-md-6">
<label>Nome do Aluno</label>
' . $optSlqUsers . '
</div>
 
<div class="form-group col-md-6">
<label>Responsável</label>
<input type="hidden" name="id_responsavel" id="id_responsavel">
<input class="form-control" type="text" readonly id="nome_responsavel" >
</div>

<div class="form-group col-md-8">
<label>Curso</label>
<select class="form-control" name="trilha">
' . $optSlqTrilhas . '
</select> 
</div>

<div class="form-group col-md-4">
<label class="text-center">Carga Horaria</label>
<input name="carga_horaria" type="number" min="10" max="500" class="form-control" value="' . (isset($dados['carga_horaria']) ? $dados['carga_horaria'] : '') . '" required>
</div>

<div class="form-group col-md-12">
<label>Modulos da Trilha</label>
<textarea rows="3" name="modulos" class="form-control" required>' . (isset($dados['modulos']) ? $dados['modulos'] : '') . '</textarea>
</div>

<div class="form-group col-md-6">
<label>Horarios</label>
<textarea name="horarios" placeholder="das 00:00 as 00:00" class="form-control" required>' . (isset($dados['horarios']) ? $dados['horarios'] : '') . '</textarea>
</div>

<div class="form-group col-md-6">
<label>Dia da Semana</label>
<textarea name="dia_semana" placeholder="Segunda e Quarta-feira" class="form-control" required>' . (isset($dados['dia_semana']) ? $dados['dia_semana'] : '') . '</textarea>
</div>

<div class="col-md-12 text-center">
<span id="obs" style="font-size: 12px;"></span>
</div>

<div class="form-group col-md-12">
<div class="form-check form-switch">
<input type="checkbox" class="form-check-input" value="1" name="bolsista" id="bolsista" onclick="syncBolsistaMatricula()" ' . (
	(isset($dados['bolsista']) && (int)$dados['bolsista'] === 1) ? 'checked' : ''
) . (!EntityMatri::temColunaBolsista() ? ' disabled' : '') . '>
<label class="form-check-label" for="bolsista"><strong>Aluno bolsista</strong> — não gera débitos / carnê</label>
</div>
'.(!EntityMatri::temColunaBolsista()
	? '<div class="form-text text-warning">Execute <code>database/matriculas_bolsista.sql</code> no phpMyAdmin para liberar esta opção.</div>'
	: '<div class="form-text" id="hint-bolsista">Com bolsa, o valor fica zerado e nenhuma parcela é lançada no caixa. As parcelas indicam só a duração do curso.</div>').'
</div>

<div class="form-group col-md-3">
<label>Aulas Sem</label>
<input name="aulas_semanais" type="number" min="1" max="20" class="form-control" value="' . (isset($dados['aulas_semanais']) ? $dados['aulas_semanais'] : '') . '" required>
</div>

<div class="form-group col-md-3">
<label>Valor Mensal</label>
<input id="valor" name="valor" type="text" class="form-control" value="' . (isset($dados['valor']) ? $dados['valor'] : '') . '"' . (
	(isset($dados['bolsista']) && (int)$dados['bolsista'] === 1) ? '' : ' required'
) . '>
</div>

<div class="form-group col-md-3">
<label>Parcelas</label>
<input name="qtd_parcelas" type="number" class="form-control" value="' . (isset($dados['qtd_parcelas']) ? $dados['qtd_parcelas'] : '') . '" min="1" max="212" required>
</div>

<div class="form-group col-md-3">
<label>Vencimento</label>
<select class="form-control" name="dia_vencimento">
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '01' ? 'selected' : '') . ' value="01">01</option>
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '05' ? 'selected' : '') . ' value="05">05</option>
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '10' ? 'selected' : '') . ' value="10">10</option>
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '15' ? 'selected' : '') . ' value="15">15</option>
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '20' ? 'selected' : '') . ' value="20">20</option>
<option ' . (isset($dados['dia_vencimento']) && $dados['dia_vencimento'] == '25' ? 'selected' : '') . ' value="25">25</option>
</select>
</div>


<div class="form-group col-md-12 text-center">
<label>Primeira Parcela</label>
<div class="row">
<div class="col-4">
<div class="form-group form-check" id="wrap-desconto-pontualidade">
<input type="checkbox" value="1" name="pontualidade" id="pontualidade" onclick="checkPontualidade()" class="form-check-input" ' . (
	(isset($dados['tipo_parcelamento']) && $dados['tipo_parcelamento'] === 'Carnê com Pix')
		? 'disabled'
		: ((isset($dados['desconto_pontualidade']) && $dados['desconto_pontualidade']) ? 'checked' : '')
) . '>
<label class="form-check-label" for="pontualidade">Desconto pontualidade</label>
<div class="form-text" id="hint-desconto-pontualidade">Somente no Carnê Simples (10% até o vencimento).</div>
</div>
</div>

<div class="col-4">
<select name="primeiromes" class="form-control">
<option ' . ($pp == 12 ? 'selected' : '') . ' value="1">Janeiro</option>
<option ' . ($pp == 1 ? 'selected' : '') . ' value="2">Fevereiro</option>
<option ' . ($pp == 2 ? 'selected' : '') . ' value="3">Março</option>
<option ' . ($pp == 3 ? 'selected' : '') . ' value="4">Abril</option>
<option ' . ($pp == 4 ? 'selected' : '') . ' value="5">Maio</option>
<option ' . ($pp == 5 ? 'selected' : '') . ' value="6">Junho</option>
<option ' . ($pp == 6 ? 'selected' : '') . ' value="7">Julho</option>
<option ' . ($pp == 7 ? 'selected' : '') . ' value="8">Agosto</option>
<option ' . ($pp == 8 ? 'selected' : '') . ' value="9">Setembro</option>
<option ' . ($pp == 9 ? 'selected' : '') . ' value="10">Outubro</option>
<option ' . ($pp == 10 ? 'selected' : '') . ' value="11">Novembro</option>
<option ' . ($pp == 11 ? 'selected' : '') . ' value="12">Dezembro</option>
</select>
</div>
<div class="col-3">
<div class="form-group">
<input name="primeiroano" type="number" class="form-control" value="' . $ano . '" required>
</div>
</div>
</div>
</div>

<div class="form-group col-md-4">
<label>Inicio das Aulas</label>
<input name="inicio" value="' . (isset($dados['inicio']) ? $dados['inicio'] : '') . '" type="date" class="form-control" required>
</div>

<div class="form-group col-md-4">
<label>Termino Aprox</label>
<input name="final" value="' . (isset($dados['fim']) ? $dados['fim'] : '') . '" type="date" class="form-control" required>
</div>
<div class="form-group col-md-4">
<label>Tipo Parcelamento</label>
<select class="form-control" name="tipo_parcelamento" id="tipo_parcelamento" onchange="syncDescontoComTipoParcelamento()">
<option ' . ((!isset($dados['tipo_parcelamento']) || $dados['tipo_parcelamento'] == 'Carnê Simples') ? 'selected' : '') . ' value="Carnê Simples">Carnê Simples</option>
'.(\App\Common\Helpers\MercadoPagoEscolaHelper::escolaTemPixAtivo((int)$id_admin) ? '
<option ' . (isset($dados['tipo_parcelamento']) && $dados['tipo_parcelamento'] == 'Carnê com Pix' ? 'selected' : '') . ' value="Carnê com Pix">Carnê com Pix (Mercado Pago)</option>
' : '').'
</select>
'.(!\App\Common\Helpers\MercadoPagoEscolaHelper::escolaTemPixAtivo((int)$id_admin) ? '<div class="form-text">PIX indisponível: configure em Configurações → Pagamentos.</div>' : '<div class="form-text">Com Mercado Pago o desconto de pontualidade fica desativado.</div>').'
</div>
' . (function () use ($dados) {
	$enc = EncargosContratoHelper::parametrosFromMatriculaRow($dados);
	if (!EntityMatri::temColunaEncargos()) {
		return '<div class="col-12"><div class="form-text text-warning small">Execute <code>database/matriculas_encargos.sql</code> no phpMyAdmin para configurar multa/juros por matrícula.</div></div>';
	}
	$vMulta = htmlspecialchars((string)$enc['multa_atraso_pct'], ENT_QUOTES, 'UTF-8');
	$vJuros = htmlspecialchars((string)$enc['juros_mora_pct_mes'], ENT_QUOTES, 'UTF-8');
	$vResc = htmlspecialchars((string)$enc['multa_cancelamento_pct'], ENT_QUOTES, 'UTF-8');
	$vCar = (int)$enc['carencia_dias'];
	return '
<div class="col-12"><hr class="my-2"><h6 class="small text-muted mb-0">Multa e juros (contrato)</h6>
<p class="form-text small mb-2">Gravados nesta matrícula — usados na simulação de atraso/cancelamento e no texto do contrato impresso.</p></div>
<div class="form-group col-md-3">
<label>Multa por atraso (%)</label>
<input name="multa_atraso_pct" type="number" step="0.01" min="0" class="form-control" value="' . $vMulta . '">
</div>
<div class="form-group col-md-3">
<label>Juros mora (% a.m.)</label>
<input name="juros_mora_pct_mes" type="number" step="0.01" min="0" class="form-control" value="' . $vJuros . '">
</div>
<div class="form-group col-md-3">
<label>Multa rescisória (%)</label>
<input name="multa_cancelamento_pct" type="number" step="0.01" min="0" class="form-control" value="' . $vResc . '">
</div>
<div class="form-group col-md-3">
<label>Carência (dias)</label>
<input name="carencia_dias" type="number" step="1" min="0" class="form-control" value="' . $vCar . '">
</div>';
})() . '
</div>

</div>
<div class="modal-footer">
<input value="' . (isset($dados['id']) ? $dados['id'] : '') . '" type="hidden" name="id">
<input value="' . ($idLeadPre > 0 ? $idLeadPre : '') . '" type="hidden" name="id_lead">
<button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary">Salvar</button>
</div>
</form>
<script>
if (typeof syncDescontoComTipoParcelamento === "function") { syncDescontoComTipoParcelamento(); }
if (typeof syncBolsistaMatricula === "function") { syncBolsistaMatricula(); }
' . ($idAlunoPre > 0 ? 'if (typeof selectAluno === "function") { selectAluno(' . (int)$idAlunoPre . '); }' : '') . '
</script>

';

return [
	'form' => $form,
];
}



public static function getNovaMatricula($request){

  $payload = self::getForm($request);
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    return json_encode([
      'form' => '<div class="alert alert-danger m-3">Erro ao montar o formulário de matrícula.</div>',
    ]);
  }
  return $json;
}

public static function setNovaMatricula($request) {
 $postVars = $request->getPostVars();

    //DADOS DO ADMIN
 $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

 $inicio = $postVars['inicio'] ?? '';
 $fim = $postVars['final'] ?? '';
 $pontualidade = !empty($postVars['pontualidade']) ? 1 : 0;
 $bolsista = !empty($postVars['bolsista']) ? 1 : 0;

$resposta = [
        "filtro" => null
    ]; 


    // Sem FILTER_SANITIZE_STRING (removido no PHP 8.2+)
 $id_aluno = filter_var($postVars['aluno'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $id_responsavel = filter_var($postVars['id_responsavel'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $id_trilha = (int)filter_var($postVars['trilha'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $carga_horaria = filter_var($postVars['carga_horaria'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $modulos = trim(strip_tags((string)($postVars['modulos'] ?? '')));
 $horarios = trim(strip_tags((string)($postVars['horarios'] ?? '')));
 $dia_semana = trim(strip_tags((string)($postVars['dia_semana'] ?? '')));
 $aulas_semanais = filter_var($postVars['aulas_semanais'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $qtd_parcelas = filter_var($postVars['qtd_parcelas'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $dia_vencimento = filter_var($postVars['dia_vencimento'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $primeiro_mes = filter_var($postVars['primeiromes'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $primeiro_ano = filter_var($postVars['primeiroano'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
 $tipo_parcelamento = trim(strip_tags((string)($postVars['tipo_parcelamento'] ?? 'Carnê Simples')));
 $encargos = EncargosContratoHelper::normalizarPostEncargos($postVars);

 if ($bolsista) {
  if (!EntityMatri::temColunaBolsista()) {
   $resposta['erro'] = 'Execute database/matriculas_bolsista.sql no phpMyAdmin para matricular bolsista.';
   return json_encode($resposta);
  }
  $valor = 0.0;
  $pontualidade = 0;
  $tipo_parcelamento = 'Carnê Simples';
  if ((int)$qtd_parcelas < 1) {
   $qtd_parcelas = 1;
  }
 } else {
  if ($tipo_parcelamento === 'Carnê com Pix'
  	&& \App\Common\Helpers\MercadoPagoEscolaHelper::escolaTemPixAtivo((int)$id_admin)) {
    // Gateway: sem desconto de pontualidade
    $pontualidade = 0;
  } else {
    $tipo_parcelamento = 'Carnê Simples';
  }

  // Substitui a vírgula por ponto no valor
  $valor = str_replace(',', '.', (string)($postVars['valor'] ?? '0'));
  // Sanitiza o valor como um número float
  $valor = filter_var($valor, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
  // Converte para float
  $valor = floatval($valor);
 }


if($id_aluno == '' or $id_aluno ==0){
    $resposta ["erro"] = 'Selecione um aluno';
    return json_encode($resposta);
}

 if(!$bolsista && $valor <= 0){
  $resposta ["erro"] = 'Defina o valor da mensalidade';
    return json_encode($resposta);
}

if (!empty($postVars['id'])) {
        $matriculaId = (int)filter_var($postVars['id'], FILTER_SANITIZE_NUMBER_INT);

        if (!TenantHelper::pertenceMatricula($matriculaId, (int)$id_admin)) {
            $resposta['erro'] = 'Matrícula não encontrada.';
            return json_encode($resposta);
        }

        // Atualizar se ID estiver presente
  $obMatricula = new EntityMatri;
  $obMatricula->id = $matriculaId;
  $obMatricula->id_aluno = $id_aluno;
  $obMatricula->id_responsavel = $id_responsavel;
  $obMatricula->id_trilha = $id_trilha;
  $obMatricula->carga_horaria = $carga_horaria;
  $obMatricula->modulos = $modulos;
  $obMatricula->horarios = $horarios;
  $obMatricula->dia_semana = $dia_semana;
  $obMatricula->aulas_semanais = $aulas_semanais;
  $obMatricula->valor = $valor;
  $obMatricula->qtd_parcelas = $qtd_parcelas;
  $obMatricula->dia_vencimento = $dia_vencimento;
  $obMatricula->primeiro_mes = $primeiro_mes;
  $obMatricula->primeiro_ano = $primeiro_ano;
  $obMatricula->desconto_pontualidade = $pontualidade;
  $obMatricula->bolsista = $bolsista;
  $obMatricula->inicio = $inicio;
  $obMatricula->fim = $fim;
  $obMatricula->tipo_parcelamento = $tipo_parcelamento;
  if (EntityMatri::temColunaEncargos()) {
   $obMatricula->multa_atraso_pct = $encargos['multa_atraso_pct'];
   $obMatricula->juros_mora_pct_mes = $encargos['juros_mora_pct_mes'];
   $obMatricula->multa_cancelamento_pct = $encargos['multa_cancelamento_pct'];
   $obMatricula->carencia_dias = $encargos['carencia_dias'];
  }

  $obMatricula->atualizar();

} else {
        // Criar nova instância se ID não estiver presente
  $obMatricula = new EntityMatri;
  $obMatricula->id_aluno = $id_aluno;
  $obMatricula->id_admin = $id_admin;
  $obMatricula->id_responsavel = $id_responsavel;
  $obMatricula->id_trilha = $id_trilha;
  $obMatricula->carga_horaria = $carga_horaria;
  $obMatricula->modulos = $modulos;
  $obMatricula->horarios = $horarios;
  $obMatricula->dia_semana = $dia_semana;
  $obMatricula->aulas_semanais = $aulas_semanais;
  $obMatricula->valor = $valor;
  $obMatricula->qtd_parcelas = $qtd_parcelas;
  $obMatricula->dia_vencimento = $dia_vencimento;
  $obMatricula->primeiro_mes = $primeiro_mes;
  $obMatricula->primeiro_ano = $primeiro_ano;
  $obMatricula->desconto_pontualidade = $pontualidade;
  $obMatricula->bolsista = $bolsista;
  $obMatricula->inicio = $inicio;
  $obMatricula->fim = $fim;
  $obMatricula->tipo_parcelamento = $tipo_parcelamento;
  if (EntityMatri::temColunaEncargos()) {
   $obMatricula->multa_atraso_pct = $encargos['multa_atraso_pct'];
   $obMatricula->juros_mora_pct_mes = $encargos['juros_mora_pct_mes'];
   $obMatricula->multa_cancelamento_pct = $encargos['multa_cancelamento_pct'];
   $obMatricula->carencia_dias = $encargos['carencia_dias'];
  }
  $obMatricula->matricular();
}

$idLeadSync = (int)($postVars['id_lead'] ?? 0);
$idUsuarioOp = (int)(parent::getIdAdmin()['usuario']['id'] ?? 0);
if ($obMatricula && (int)$id_aluno > 0) {
  CrmPessoaHelper::sincronizarLeadMatriculado(
    (int)$id_admin,
    (int)$id_aluno,
    $idUsuarioOp,
    $idLeadSync > 0 ? $idLeadSync : null
  );
}

if(!$obMatricula){
        $resposta ["erro"] = 'Erro ao matricular';
    }
    return json_encode($resposta);


}



public static function simularCancelamento($request) {
  $postVars = $request->getPostVars();
  $id = (int)($postVars['id'] ?? 0);
  $id_admin = parent::getIdAdminInt();
  return json_encode(self::simularAcertoFinanceiroRequest(
    $id,
    $id_admin,
    $postVars,
    'cancelar'
  ), JSON_UNESCAPED_UNICODE);
}

public static function simularRegularizacaoFinanceira($request) {
  $postVars = $request->getPostVars();
  $id = (int)($postVars['id'] ?? 0);
  $id_admin = parent::getIdAdminInt();
  return json_encode(self::simularAcertoFinanceiroRequest(
    $id,
    $id_admin,
    $postVars,
    'regularizar'
  ), JSON_UNESCAPED_UNICODE);
}

/** @return array<string,mixed> */
private static function simularAcertoFinanceiroRequest(int $id, int $id_admin, array $postVars, string $modo): array {
  $parcelasCobrar = self::normalizarIdsParcelas($postVars['parcelas_cobrar'] ?? null);
  if (trim((string)($postVars['preset'] ?? '')) === 'politica_desistencia') {
    $base = $modo === 'regularizar'
      ? EncargosContratoHelper::simularRegularizacaoFinanceira($id, $id_admin, [])
      : EncargosContratoHelper::simularCancelamento($id, $id_admin, []);
    if (empty($base['ok'])) {
      return $base;
    }
    $parcelasCobrar = EncargosContratoHelper::parcelasCobrarPresetPoliticaDesistencia(
      $base['parcelas'] ?? [],
      (int)($base['max_parcelas_desistencia'] ?? EncargosContratoHelper::DEFAULT_MAX_PARCELAS_DESISTENCIA)
    );
  }
  if ($modo === 'regularizar') {
    return EncargosContratoHelper::simularRegularizacaoFinanceira($id, $id_admin, $parcelasCobrar);
  }
  return EncargosContratoHelper::simularCancelamento($id, $id_admin, $parcelasCobrar);
}

/** @return int[]|null null = padrão (vencidas sim, futuras não); [] = nenhuma selecionada */
private static function normalizarIdsParcelas($raw): ?array {
  if ($raw === null) {
    return null;
  }
  if (is_string($raw)) {
    $trim = trim($raw);
    if ($trim === '') {
      return [];
    }
    $decoded = json_decode($trim, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $raw = $decoded;
    } else {
      $raw = preg_split('/\s*,\s*/', $trim, -1, PREG_SPLIT_NO_EMPTY);
    }
  }
  if (!is_array($raw)) {
    return [];
  }
  $ids = [];
  foreach ($raw as $v) {
    $id = (int)$v;
    if ($id > 0) {
      $ids[] = $id;
    }
  }
  return array_values(array_unique($ids));
}

public static function cancelarMatricula($request){

  $postVars = $request->getPostVars();
  $id = (int)($postVars['id'] ?? 0);
  $id_admin = parent::getIdAdminInt();
  $parcelasCobrar = self::normalizarIdsParcelas($postVars['parcelas_cobrar'] ?? []) ?? [];

  if (!TenantHelper::pertenceMatricula($id, $id_admin)) {
    return json_encode(['ok' => false, 'message' => 'Matrícula não encontrada.']);
  }

  $mat = EntityMatri::getMatriculaById($id);
  if (!$mat || (int)$mat->status !== MatriculaStatusHelper::STATUS_ANDAMENTO) {
    return json_encode(['ok' => false, 'message' => 'Só é possível cancelar matrícula em andamento.']);
  }

  $sim = EncargosContratoHelper::simularCancelamento($id, $id_admin, $parcelasCobrar);
  if (empty($sim['ok'])) {
    return json_encode(['ok' => false, 'message' => $sim['message'] ?? 'Falha ao simular cancelamento.']);
  }

  $obUsers = new EntityMatri;
  $obUsers->id = $id;
  $obUsers->cancelar(date('Y-m-d'));

  $baixadas = MatriculaStatusHelper::baixarParcelasExceto($id, $id_admin, $parcelasCobrar);

  $tituloMultaId = null;
  if ((float)($sim['multa_rescisoria'] ?? 0) > 0) {
    $tituloMultaId = EncargosContratoHelper::lancarMultaRescisoria(
      $id_admin,
      (int)$mat->id_aluno,
      $id,
      (float)$sim['multa_rescisoria']
    );
  }

  $msg = 'Contrato cancelado.';
  if ((int)($sim['qtd_baixar'] ?? 0) > 0) {
    $msg .= ' '.$baixadas.' parcela(s) baixada(s) com R$ 0 (histórico preservado).';
  }
  if ((int)($sim['qtd_cobrar'] ?? 0) > 0) {
    $msg .= ' '.(int)$sim['qtd_cobrar'].' parcela(s) permanecem em aberto para quitação (total com encargos até hoje: R$ '
      .NumeroHelper::moedaBr((float)($sim['total_cobrar_com_encargos'] ?? 0)).').';
  } else {
    $msg .= ' Nenhuma parcela ficou em aberto para cobrança.';
  }
  if ($tituloMultaId) {
    $msg .= ' Título de multa rescisória #'.$tituloMultaId.' em aberto (R$ '.NumeroHelper::moedaBr((float)$sim['multa_rescisoria']).') — quite no carnê ou extrato do aluno.';
  } elseif ((float)($sim['multa_rescisoria'] ?? 0) <= 0) {
    $msg .= ' Multa rescisória não aplicável (nenhuma parcela cancelada com baixa administrativa).';
  }

  return json_encode([
    'ok' => true,
    'message' => $msg,
    'parcelas_baixadas' => $baixadas,
    'parcelas_cobrar' => count($parcelasCobrar),
    'titulo_multa_id' => $tituloMultaId,
    'simulacao' => $sim,
  ], JSON_UNESCAPED_UNICODE);
}

public static function regularizarFinanceiro($request) {
  $postVars = $request->getPostVars();
  $id = (int)($postVars['id'] ?? 0);
  $id_admin = parent::getIdAdminInt();
  $parcelasCobrar = self::normalizarIdsParcelas($postVars['parcelas_cobrar'] ?? []) ?? [];

  if (!TenantHelper::pertenceMatricula($id, $id_admin)) {
    return json_encode(['ok' => false, 'message' => 'Matrícula não encontrada.']);
  }

  $mat = EntityMatri::getMatriculaById($id);
  if (!$mat || (int)$mat->status !== MatriculaStatusHelper::STATUS_ENCERRADO) {
    return json_encode(['ok' => false, 'message' => 'Só é possível regularizar matrícula encerrada.']);
  }

  $sim = EncargosContratoHelper::simularRegularizacaoFinanceira($id, $id_admin, $parcelasCobrar);
  if (empty($sim['ok'])) {
    return json_encode(['ok' => false, 'message' => $sim['message'] ?? 'Falha ao simular regularização.']);
  }

  $obsBaixa = 'Regularização financeira matrícula #'.$id;
  $baixadas = MatriculaStatusHelper::baixarParcelasExceto($id, $id_admin, $parcelasCobrar, $obsBaixa);

  $tituloMultaId = null;
  $multaRescisoria = (float)($sim['multa_rescisoria'] ?? 0);
  if ($multaRescisoria > 0 && !EncargosContratoHelper::temMultaRescisoriaAberta($id, $id_admin)) {
    $tituloMultaId = EncargosContratoHelper::lancarMultaRescisoria(
      $id_admin,
      (int)$mat->id_aluno,
      $id,
      $multaRescisoria
    );
  }

  $msg = 'Financeiro regularizado. O contrato permanece encerrado.';
  if ((int)($sim['qtd_baixar'] ?? 0) > 0) {
    $msg .= ' '.$baixadas.' parcela(s) baixada(s) com R$ 0 (histórico preservado).';
  }
  if ((int)($sim['qtd_cobrar'] ?? 0) > 0) {
    $msg .= ' '.(int)$sim['qtd_cobrar'].' parcela(s) permanecem em aberto para quitação (total com encargos até hoje: R$ '
      .NumeroHelper::moedaBr((float)($sim['total_cobrar_com_encargos'] ?? 0)).').';
  } else {
    $msg .= ' Nenhuma parcela ficou em aberto para cobrança.';
  }
  if ($tituloMultaId) {
    $msg .= ' Título de multa rescisória #'.$tituloMultaId.' em aberto (R$ '.NumeroHelper::moedaBr($multaRescisoria).').';
  } elseif ($multaRescisoria > 0 && EncargosContratoHelper::temMultaRescisoriaAberta($id, $id_admin)) {
    $msg .= ' Multa rescisória já existente em aberto — nenhum título duplicado foi gerado.';
  } elseif ($multaRescisoria <= 0) {
    $msg .= ' Multa rescisória não aplicável (nenhuma parcela com baixa administrativa).';
  }

  return json_encode([
    'ok' => true,
    'message' => $msg,
    'baixadas' => $baixadas,
    'id_multa' => $tituloMultaId,
  ], JSON_UNESCAPED_UNICODE);
}

public static function encerrarMatricula($request){

  $postVars = $request->getPostVars();
  $id = (int)($postVars['id'] ?? 0);
  $id_admin = parent::getIdAdminInt();

  if (!TenantHelper::pertenceMatricula($id, $id_admin)) {
    return json_encode(['ok' => false, 'message' => 'Matrícula não encontrada.']);
  }
  if (MatriculaStatusHelper::contarTitulosAbertosMatricula($id, $id_admin) > 0) {
    return json_encode([
      'ok' => false,
      'message' => 'Existem parcelas em aberto nesta matrícula. Use Cancelar contrato para regularizar o financeiro antes de encerrar.',
    ], JSON_UNESCAPED_UNICODE);
  }

  if (!MatriculaStatusHelper::encerrarMatricula($id, $id_admin)) {
    return json_encode(['ok' => false, 'message' => 'Não foi possível encerrar esta matrícula.']);
  }

  return json_encode(['ok' => true, 'message' => 'Matrícula encerrada.']);
}

public static function verContrato($request,$id){

  $userLogedData = SessionUser::getUserLogedData();
  $id = (int)$id;
  $id_admin = (int)$userLogedData['usuario']['id_admin'];

  if (!in_array('contratos', ModuleGateHelper::getSlugsEscola($id_admin), true)) {
    return 'Contrato não disponível para esta escola.';
  }

  if (!TenantHelper::pertenceMatricula($id, $id_admin)) {
    return 'Matrícula não encontrada.';
  }

  $escolaEnt = ClientesAssinantes::getEscolaById($id_admin);
  if (!$escolaEnt instanceof ClientesAssinantes) {
    $escolaEnt = null;
  }

  $vars = ContratoVariaveisBuilder::montarFromMatricula($id, $userLogedData['escola'] ?? []);

  return ContratoTemplateHelper::render($vars, $escolaEnt);
}



}