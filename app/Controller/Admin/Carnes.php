<?php 

  namespace App\Controller\Admin;
  use \App\Utils\View;
  use \App\Model\Entity\Matriculas as EntityMatri;
  use \App\Model\Entity\User as EntityUser;
  use \App\Model\Entity\Trilhas as EntityTrilhas;
  use \App\Model\Db\Pagination;
  use \App\Session\User\Login as SessionUser;
  use \App\Common\Helpers\DateTimeHelper;
  use \App\Common\Helpers\NumeroHelper;
  use \App\Model\Entity\Caixa;
  use \App\Common\Helpers\TenantHelper;
  use \App\Common\Helpers\BrandingHelper;
  use \App\Common\Helpers\MatriculaStatusHelper;
  use \App\Common\Helpers\FinanceiroAlunoHelper;
  use \App\Common\Helpers\EncargosContratoHelper;
  use \App\Model\Entity\Responsaveis as EntityRes;

  class Carnes extends Page{

    //RETORNA O FORMULARIO
    public static function index($request){
      //CONTEÚDO DE FORMULÁRIO
      $content = View::render('admin/modules/carnes/index',[]);

      //RETORNA A PÁGINA COMPLETA
       /**
        * TITULO DA PAGINA
        * CONTEUDO
        * CURRENTSESSION SESSÃO ATUAL
        * REQUEST SE NESCESSÁRIO
        */
      return parent::getPanel('Carnês',$content,'Financeiro');
    }

    private static function getItens($request,&$obPagination){

      //DADOS DO ADMIN
      $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

      // POSTS
      $postVars = $request->getPostVars();
      $id_cliente = (isset($postVars['filtro']) && $postVars['filtro'] !== '' && $postVars['filtro'] !== '0' && (int)$postVars['filtro'] > 0)
        ? (int)$postVars['filtro'] : 0;
      $busca = trim((string)($postVars['busca'] ?? ''));
      $statusFiltro = trim((string)($postVars['status'] ?? ''));
      $parcelaFiltro = trim((string)($postVars['parcela'] ?? ''));

      //PAGINA ATUAL
      $paginaAtual = $postVars['page'] ?? 1;

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
      if ($parcelaFiltro === 'aberto') {
        $where .= ' AND id IN (SELECT id_ref FROM caixa WHERE id_admin = '.(int)$id_admin.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status').')';
      } else      if ($parcelaFiltro === 'atraso') {
        $where .= ' AND id IN (SELECT id_ref FROM caixa WHERE id_admin = '.(int)$id_admin
          .' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status').' AND vencimento < CURDATE())';
      }

      MatriculaStatusHelper::encerrarVencidasTenant((int)$id_admin);

      //QUANTIDADE TOTAL DE REGISTROS
      $quantidadeTotal = (int)(EntityMatri::getMatriculas($where,null,null,'COUNT(*) as qtd')->fetchObject()->qtd ?? 0);

      //INSTANCIA DE PAGINAÇÃO
      $obPagination = new Pagination($quantidadeTotal,$paginaAtual,10);

      //RESULTADOS DA PAGINA
      $results = EntityMatri::getMatriculas($where, 'id DESC', $obPagination->getLimit()); 


      //REDERIZA O ITEM
      $itens = '';

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

        $total = $dados->qtd_parcelas * $dados->valor;
        if($dados->status == 0){
          $status = 'Em andamento';
        } else if($dados->status == 1){
          $status = 'Encerrado';
          $disabled='disabled';
        } else {
          $status = 'Cancelado';
          $disabled='disabled';
        }

        $itens .= 
        '<tr>
        <td>'.$dados->id.'</td>
        <td>'.$nomeAluno.'</td>
        <td>'.$nomeTrilha.'</td>
        <td><span>R$ '.NumeroHelper::moedaBr($total).'</span></td>
        <td>'.$status.'</td>
        <td>
        <div class="dropdown">
        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="far fa-edit fa-lg"></i>
        </button>
        <ul class="dropdown-menu">
        <li>
        <a class="dropdown-item" href="#" onclick="list_itens('.$dados->id.', \'listar_titulos\')">
        <i class="fa-regular fa-file-lines fa-lg"></i> Ver títulos</a>
        </li>
        <li>
        <a class="dropdown-item" target="_blank" href="'.URL.'/painel/carnes/'.$dados->id.'" >
        <i class="fa-regular fa-clone fa-lg"></i> Gerar 2ªVia</a>
        </li>
        </ul>
        </div>
        </td>
        </tr>';

      }

      if ($itens === '') {
        $itens = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum carnê encontrado com esses filtros.</td></tr>';
      }

      $table = '
      <div class="card-body">
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
      'itens' => self::getItens($request,$obPagination),
      'pagination' => parent::getPagination($request,$obPagination)
    ];

    return parent::jsonLista($conteudo);

  }




  public static function getList($request){

    $form = self::getForm($request);
    return json_encode($form);
  }

  public static function getForm($request) {
    $postVars = $request->getPostVars();

    if (isset($postVars['funcao']) && $postVars['funcao'] == 'listar_titulos') {
      $id_admin = parent::getIdAdminInt();
      $matriculaId = (int)($postVars['id'] ?? 0);

      if (!TenantHelper::pertenceMatricula($matriculaId, $id_admin)) {
        return json_encode(['erro' => 'Matrícula não encontrada.']);
      }

      $results = Caixa::getCaixa('id_ref = '.$matriculaId.' AND id_admin = '.$id_admin);
    } 
    
      // Inicializa a tabela
    $table = '';

      // Carrega o SELECT
    while ($obDados = $results->fetchObject(Caixa::class)) {

      if (FinanceiroAlunoHelper::tituloPago($obDados->status)) {
        $status = 'Pago';
        $baixaIcon = 'disabled';
        $reciboIcon = '';
        $icon = '<i class="fa-solid fa-circle-check fa-lg text-success"></i>';
      } else {
        $status = 'Em aberto';
        $baixaIcon = '';
        $reciboIcon = 'disabled';
            $icon = '<i class="far fa-edit fa-lg"></i>';
      }
      
      $data_pagamento = $obDados->data_pagamento ? DateTimeHelper::databr($obDados->data_pagamento) : '__/__/____';

      $table .= '
      <tr>
      <td>'.$obDados->descricao.'</td>
      <td>'.DateTimeHelper::databr($obDados->vencimento).'</td>
      <td><span>'.NumeroHelper::moedaBr($obDados->valor).'</span></td>
      <td><span>'.NumeroHelper::moedaBr($obDados->valor_pago).'</span></td>
      <td>'.$data_pagamento.'</td>
      <td>'.$status.'</td>
      <td>
      <a class="dropdown-item '.$baixaIcon.'" href="#" title="Adicionar ao carrinho para pagar" onclick="addCarrinhoTitulo('.$obDados->id.'); return false;">
      <i class="fa-solid fa-cart-plus fa-lg"></i>
      </a>

      <a class="dropdown-item '.$reciboIcon.'" title="Imprimir Recibo" target="_blank" href="'.URL.'/painel/carnes/recibo/'.$obDados->id.'" >
        <i class="fas fa-print fa-lg"></i></a>
      <a class="dropdown-item '.$reciboIcon.'" href="#" title="Comprovante (A4 / enviar)" onclick="reciboMenu('.$obDados->id.'); return false;">
        <i class="fas fa-receipt fa-lg"></i></a>

      </td>

      </tr>';
    }

      // Renderiza a tabela
    $table = '
    <div class="modal-header">
    <h1 class="modal-title fs-5" id="exampleModalLabel">Títulos</h1>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="card-body">
    <div class="table-responsive">
    <table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
    <thead>
    <tr>
    <th>Título</th>
    <th>Vencimento</th>
    <th>Valor R$</th>
    <th>pago R$</th>
    <th>Data pgt</th>
    <th>Status pgt</th>
    <th>Ações</th>
    </tr>
    </thead>
    <tbody>'.$table.'</tbody>
    </table>
    </div>
    </div>';

    return $table;
  }

  public static function darBaixa($request){

   //DADOS DO USUARIO
   $nivel = parent::getIdAdmin()['usuario']['nivel'];

   $habilitado = ($nivel == 'Diretor') ? '' : 'readonly';

   $postVars = $request->getPostVars();
   $id_admin = parent::getIdAdminInt();
   $caixaId = (int)($postVars['id'] ?? 0);

   if (!TenantHelper::pertenceCaixa($caixaId, $id_admin)) {
     return json_encode(['erro' => 'Título não encontrado.']);
   }

   $dados = (array) Caixa::getCaixaById($caixaId);
   $obMatricula = (array) EntityMatri::getMatriculaById($dados['id_ref']);

  $dadosUser = (array) EntityUser::getUserById($obMatricula['id_aluno']);

   $dias = DateTimeHelper::subtrairDatas($dados['vencimento'],DateTimeHelper::hoje())->d;

   $pont = FinanceiroAlunoHelper::calcularPontualidade(
     (float)($dados['valor'] ?? 0),
     $dados['vencimento'] ?? '',
     $obMatricula['desconto_pontualidade'] ?? 0
   );
   $desconto = $pont['desconto'];
   $valorComDesconto = $pont['valor_com_desconto'];
   $valorPagar = $pont['valor_pagar'];

   $blocoEncargos = '';
   $idRef = (int)($dados['id_ref'] ?? 0);
   if ($idRef > 0 && ($dados['vencimento'] ?? '') !== '' && ($dados['vencimento'] ?? '') <= DateTimeHelper::hoje()) {
     $paramsEnc = EncargosContratoHelper::parametrosPorMatricula($idRef);
     $enc = EncargosContratoHelper::calcularAtraso(
       $valorPagar,
       (string)($dados['vencimento'] ?? ''),
       $paramsEnc
     );
     if (!empty($enc['elegivel_encargos'])) {
       $blocoEncargos = EncargosContratoHelper::htmlBlocoPagamento($enc);
       $valorPagar = $enc['total_com_encargos'];
     }
   }

   if(($dados['vencimento'] ?? '') > DateTimeHelper::hoje()){
    $vencido = 'vence em';
  } else {
    $vencido = 'vencido há';
  }

  $vencimento = DateTimeHelper::databr($dados['vencimento']);

  $form = '<form id="form" method="post">
  <div class="modal-header">
  <h1 class="modal-title fs-5" id="exampleModalLabel">Titulo a Receber</h1>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>
  <div class="modal-body">

  <div id="response"></div>

  <ul class="list-group mb-3 col-md-12">

  <li class="list-group-item d-flex justify-content-between lh-sm">
  <div>
  <h6 class="my-0">Descrição do titulo</h6>
  <small class="text-muted">' . @$dados['descricao'] . '</small>
  </div>
  </li>

  <li class="list-group-item d-flex justify-content-between lh-sm">
  <div>
  <h6 class="my-0">Valor Original</h6>
  <small class="text-muted">' .'R$ '. NumeroHelper::moedaBr(@$dados['valor']) . '</small>
  </div>
  <span class="text-muted"></span>
  </li>

  <li class="list-group-item d-flex justify-content-between lh-sm">
  <div>
  <h6 class="my-0">Data de vencimento</h6>
  <small class="text-muted">'.$vencimento.'</small>
  </div>
  <span class="text-muted">'.$vencido.' '.$dias.' dias</span>
  </li>
  ';

  if ($pont['elegivel']) {
    $form .= '
  <li class="list-group-item d-flex justify-content-between lh-sm">
  <div>
  <h6 class="my-0">Desconto pontualidade</h6>
  <small class="text-muted">'.'R$ '.NumeroHelper::moedaBr($valorComDesconto).'</small>
  </div>
  <span class="text-muted">'.'Valor do desconto: R$ '.NumeroHelper::moedaBr($desconto).'</span>
  </li>';
  }

  if ($blocoEncargos !== '') {
    $form .= $blocoEncargos . '
  <li class="list-group-item d-flex justify-content-between enc-total-row">
  <span>Total a pagar</span>
  <strong id="enc_total_label">R$ '.NumeroHelper::moedaBr($valorPagar).'</strong>
  </li>';
  } elseif ($pont['elegivel']) {
    $form .= '
  <li class="list-group-item d-flex justify-content-between">
  <span>Total a pagar</span>
  <strong>R$ '.NumeroHelper::moedaBr($valorPagar).'</strong>
  </li>';
  } else {
    $form .= '
  <li class="list-group-item d-flex justify-content-between">
  <span>Total a pagar</span>
  <strong>R$ '.NumeroHelper::moedaBr($valorPagar).'</strong>
  </li>';
  }

  $form .= '
  </ul>

  <input value="' . @$valorPagar. '" type="hidden" id="valor_pagar" name="valor_pagar">

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
  <input type="datetime-local" name="data_pagamento" '.$habilitado.' value="'.DateTimeHelper::agora().'" class="form-control">
  </div>

  <div class="form-group col-md-6">
  <label>Valor recebido</label>
  <input type="text" id="valor_recebido" name="valor_recebido" class="form-control" oninput="calcularTroco()" required>
  </div>

  <div class="form-group col-md-6">
  <label>Troco</label>
  <input type="text" id="troco" readonly class="form-control">
  </div>

  </div>

  </div>
  <div class="modal-footer">
  <input value="' . @$dados['id'] . '" type="hidden" name="id">
  <input value="' . @$dadosUser['id'] . '" type="hidden" name="id_aluno">
  <button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
  <button type="submit" class="btn btn-primary">Salvar</button>
  </div>
  </form>';

  return $form;

  }

 public static function recibo($request, $id, $larguraPapel = null) {
    $id = (int)$id;
    $id_admin = parent::getIdAdminInt();
    if ($larguraPapel === null) {
      $larguraPapel = self::formatoPapelFromRequest($request);
    }

    if (!TenantHelper::pertenceCaixa($id, $id_admin)) {
      return 'Recibo não encontrado.';
    }

    $dados = (array) Caixa::getCaixaById($id);
    $userLogedData = SessionUser::getUserLogedData();
    $escola = $userLogedData['escola'] ?? [];
    $logoUrl = htmlspecialchars(BrandingHelper::urlLogoEscola($escola['logo'] ?? null), ENT_QUOTES, 'UTF-8');
    $nomeEscola = htmlspecialchars((string)($escola['nome'] ?? 'Escola'), ENT_QUOTES, 'UTF-8');
    $cnpjEscola = htmlspecialchars((string)($escola['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8');
    $siteEscola = trim((string)($escola['site'] ?? ''));
    if ($siteEscola === '') {
      $siteEscola = 'www.ctieducacional.com.br';
    }
    $siteEscola = htmlspecialchars($siteEscola, ENT_QUOTES, 'UTF-8');
    $cidadeLinha = '';
    $cidadeRaw = trim((string)($escola['cidade'] ?? ''));
    $estadoRaw = trim((string)($escola['estado'] ?? ''));
    if ($cidadeRaw !== '' || $estadoRaw !== '') {
      if (ctype_digit($cidadeRaw) || ctype_digit($estadoRaw)) {
        try {
          $cid = ctype_digit($cidadeRaw)
            ? \App\Model\Entity\EstadoCidades::getCidades('id = '.(int)$cidadeRaw)->fetchObject()
            : null;
          $est = ctype_digit($estadoRaw)
            ? \App\Model\Entity\EstadoCidades::getEstados('id = '.(int)$estadoRaw)->fetchObject()
            : null;
          $parts = [];
          if ($cid && !empty($cid->nome)) {
            $parts[] = $cid->nome;
          } elseif ($cidadeRaw !== '' && !ctype_digit($cidadeRaw)) {
            $parts[] = $cidadeRaw;
          }
          if ($est && !empty($est->sigla)) {
            $parts[] = $est->sigla;
          } elseif ($estadoRaw !== '' && !ctype_digit($estadoRaw)) {
            $parts[] = $estadoRaw;
          }
          $cidadeLinha = htmlspecialchars(implode(' - ', $parts), ENT_QUOTES, 'UTF-8');
        } catch (\Throwable $e) {
          $cidadeLinha = '';
        }
      } else {
        $cidadeLinha = htmlspecialchars(trim($cidadeRaw.($estadoRaw !== '' ? ' - '.$estadoRaw : '')), ENT_QUOTES, 'UTF-8');
      }
    }

    // Definimos a largura útil (Safe Zone)
    $isA4 = ($larguraPapel === 'a4');
    $larguraUtil = $isA4 ? '180mm' : '52mm';

    $pageCss = $isA4
      ? '@page { size: A4; margin: 15mm; }'
      : '@page { size: 58mm auto; margin: 0; }';
    $bodyCss = $isA4
      ? 'width: 100%; max-width: 180mm; margin: 0 auto; padding: 0;'
      : 'width: 48mm; margin-left: 0; margin-right: 0; padding: 0; overflow: hidden;';
    $fontCss = $isA4 ? '13px' : '11px';
    $logoCss = $isA4 ? 'max-width:120mm;max-height:35mm;' : 'max-width:42mm;max-height:18mm;';

    $reciboHtml = '
    <style>
    '.$pageCss.'

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: Arial, sans-serif;
        font-size: '.$fontCss.';
        color: #000;
    }

    body {
        '.$bodyCss.'
    }

    .center { text-align: center; width: 100%; }
    .right { text-align: right; }
    
    .line {
        border-top: 1px dashed #000;
        margin: '.($isA4 ? '8px' : '4px').' 0;
        width: 100%;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    td {
        padding: '.($isA4 ? '3px' : '1px').' 0;
        word-wrap: break-word;
    }

    .small { font-size: '.($isA4 ? '12px' : '10px').'; }
    
    strong { font-weight: bold; }
</style>

<body>
        <div class="center">
            <img src="'.$logoUrl.'" alt="" style="'.$logoCss.'object-fit:contain;"><br>
            <strong>'.$nomeEscola.'</strong><br>
            '.($cidadeLinha !== '' ? $cidadeLinha.'<br>' : '').'
            '.($cnpjEscola !== '' ? 'CNPJ: '.$cnpjEscola : '').'
        </div>

        <div class="line"></div>

        <div>
            Recibo nº: <strong>'.$dados['id'].'</strong><br>
            Data: '.DateTimeHelper::databr($dados['data_pagamento']).'<br>
            Hora: '.DateTimeHelper::extrairHorario($dados['data_pagamento']).'
        </div>

        <div class="line"></div>

        <div>
            Cliente / Descrição:<br>
            <strong>'.mb_strtoupper($dados['descricao']).'</strong>
        </div>

        <div class="line"></div>

        <table>
            <thead>
                <tr>
                    <th align="left">Item</th>
                    <th align="right">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Mensalidade</td>
                    <td class="right">R$ '.NumeroHelper::moedaBr($dados['valor_pago']).'</td>
                </tr>
            </tbody>
        </table>

        <div class="line"></div>

        <table>
            <tr>
                <td><strong>Total</strong></td>
                <td class="right"><strong>R$ '.NumeroHelper::moedaBr($dados['valor_pago']).'</strong></td>
            </tr>
            <tr>
                <td class="small">Forma de Pgto:</td>
                <td class="right small">'.$dados['tipo_pagamento'].'</td>
            </tr>
        </table>

        <div class="line"></div>

        <div class="small center">
            Referente a baixa de mensalidade.<br>
            Documento sem valor fiscal.
        </div>

        <div class="line"></div>

        <div class="center small">
            Obrigado pela preferência!<br>
            <strong>'.$siteEscola.'</strong>
        </div>

        <br>
        <div class="center">.</div> </body>';

    $content = View::render('admin/modules/carnes/recibo', [
        'title' => 'Recibo de pagamento',
        'show-recibo' => $reciboHtml,
    ]);

    return $content;
}

/**
 * Comprovante de várias baixas do carrinho (ids=1,2,3&formato=a4|58mm).
 */
private static function formatoPapelFromRequest($request): string {
	$q = $request->getQueryParams() ?: [];
	$fmt = strtolower(trim((string)($q['formato'] ?? $q['papel'] ?? '58mm')));
	return $fmt === 'a4' ? 'a4' : '58mm';
}

/** @return list<int> */
private static function parseReciboIdsFromInput($request, int $id_admin, ?array $post = null): array {
	$ids = [];
	$raw = '';
	if (is_array($post)) {
		$raw = (string)($post['ids'] ?? '');
	}
	if ($raw === '') {
		$q = $request->getQueryParams() ?: [];
		$raw = (string)($q['ids'] ?? '');
	}
	foreach (preg_split('/[,\s]+/', $raw) as $p) {
		$id = (int)$p;
		if ($id > 0 && TenantHelper::pertenceCaixa($id, $id_admin)) {
			$ids[$id] = $id;
		}
	}
	return array_values($ids);
}

/**
 * @return array{
 *   rows: list<array{id:int,desc:string,valor:float,id_ref:int}>,
 *   total: float,
 *   tipoPagamento: string,
 *   dataPagamento: string
 * }|null
 */
private static function dadosReciboLote(array $ids, int $id_admin): ?array {
	$rows = [];
	$total = 0.0;
	$tipoPagamento = '';
	$dataPagamento = '';
	foreach ($ids as $id) {
		$ob = Caixa::getCaixaById($id);
		if (!$ob) {
			continue;
		}
		$dados = (array)$ob;
		$valor = (float)($dados['valor_pago'] ?? 0);
		$total += $valor;
		if ($tipoPagamento === '' && !empty($dados['tipo_pagamento'])) {
			$tipoPagamento = (string)$dados['tipo_pagamento'];
		}
		if ($dataPagamento === '' && !empty($dados['data_pagamento'])) {
			$dataPagamento = (string)$dados['data_pagamento'];
		}
		$desc = trim((string)($dados['descricao'] ?? 'Item'));
		if ($desc === '') {
			$desc = 'Mensalidade';
		}
		$rows[] = [
			'id' => (int)$dados['id'],
			'desc' => mb_strtoupper($desc),
			'valor' => $valor,
			'id_ref' => (int)($dados['id_ref'] ?? 0),
		];
	}
	if (!$rows) {
		return null;
	}
	return [
		'rows' => $rows,
		'total' => $total,
		'tipoPagamento' => $tipoPagamento,
		'dataPagamento' => $dataPagamento,
	];
}

/** @param array{rows:list<array>,total:float,tipoPagamento:string,dataPagamento:string,nomeEscola?:string} $dados */
private static function textoReciboLoteResumo(array $dados): string {
	$linhas = [];
	$linhas[] = 'Comprovante de pagamento';
	if (!empty($dados['nomeEscola'])) {
		$linhas[] = (string)$dados['nomeEscola'];
	}
	$linhas[] = '';
	$linhas[] = 'Recibos: '.implode(', ', array_column($dados['rows'], 'id'));
	$linhas[] = 'Data: '.DateTimeHelper::databr($dados['dataPagamento']).' '.DateTimeHelper::extrairHorario($dados['dataPagamento']);
	$linhas[] = '';
	foreach ($dados['rows'] as $r) {
		$linhas[] = '- '.$r['desc'].': R$ '.NumeroHelper::moedaBr($r['valor']);
	}
	$linhas[] = '';
	$linhas[] = 'Total: R$ '.NumeroHelper::moedaBr($dados['total']);
	if (!empty($dados['tipoPagamento'])) {
		$linhas[] = 'Forma de pagamento: '.$dados['tipoPagamento'];
	}
	$linhas[] = '';
	$linhas[] = 'Documento sem valor fiscal. Obrigado pela preferência!';
	return implode("\n", $linhas);
}

/** @param list<array{id_ref:int}> $rows */
private static function contatoAlunoRecibo(array $rows): array {
	foreach ($rows as $r) {
		$idRef = (int)($r['id_ref'] ?? 0);
		if ($idRef <= 0) {
			continue;
		}
		$mat = EntityMatri::getMatriculaById($idRef);
		if (!$mat) {
			continue;
		}
		$idAluno = (int)($mat->id_aluno ?? 0);
		if ($idAluno <= 0) {
			continue;
		}
		$user = EntityUser::getUserById($idAluno);
		if (!$user) {
			continue;
		}
		$email = trim((string)($user->email ?? ''));
		$whatsapp = trim((string)($user->whatsapp ?? ''));
		if ($email !== '' || $whatsapp !== '') {
			return [
				'nome' => trim((string)($user->nome ?? '')),
				'email' => $email,
				'whatsapp' => $whatsapp,
			];
		}
	}
	return ['nome' => '', 'email' => '', 'whatsapp' => ''];
}

public static function enviarReciboLote($request) {
	$id_admin = parent::getIdAdminInt();
	$post = $request->getPostVars() ?: [];
	$ids = self::parseReciboIdsFromInput($request, $id_admin, is_array($post) ? $post : []);
	if (!$ids) {
		return json_encode(['erro' => 'Nenhum título válido para envio.']);
	}
	$canal = strtolower(trim((string)($post['canal'] ?? '')));
	if (!in_array($canal, ['email', 'whatsapp', 'ambos'], true)) {
		return json_encode(['erro' => 'Canal inválido. Use email, whatsapp ou ambos.']);
	}

	$dados = self::dadosReciboLote($ids, $id_admin);
	if (!$dados) {
		return json_encode(['erro' => 'Nenhum título válido para envio.']);
	}

	$userLogedData = SessionUser::getUserLogedData();
	$escola = $userLogedData['escola'] ?? [];
	$nomeEscola = trim((string)($escola['nome'] ?? 'Escola'));
	$dados['nomeEscola'] = $nomeEscola;
	$texto = self::textoReciboLoteResumo($dados);
	$html = nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'));
	$contato = self::contatoAlunoRecibo($dados['rows']);

	$enviados = [];
	$erros = [];

	if ($canal === 'email' || $canal === 'ambos') {
		$email = trim((string)($contato['email'] ?? ''));
		if ($email === '') {
			$erros[] = 'E-mail do aluno não encontrado.';
		} else {
			$mail = \App\Common\Communication\Email::escola($id_admin);
			$assunto = 'Comprovante de pagamento — '.$nomeEscola;
			if ($mail->sendEmail($email, $assunto, $html)) {
				$enviados[] = 'e-mail';
			} else {
				$erros[] = $mail->getError() ?: 'Falha ao enviar e-mail.';
			}
		}
	}

	if ($canal === 'whatsapp' || $canal === 'ambos') {
		$tel = trim((string)($contato['whatsapp'] ?? ''));
		if ($tel === '') {
			$erros[] = 'WhatsApp do aluno não encontrado.';
		} else {
			$envio = \App\Common\Communication\WhatsappEscolaService::enviarTexto($id_admin, $tel, $texto);
			if (!empty($envio['ok'])) {
				$enviados[] = 'WhatsApp';
			} else {
				$erros[] = (string)($envio['message'] ?? 'Falha ao enviar WhatsApp.');
			}
		}
	}

	if ($enviados) {
		return json_encode([
			'sucesso' => true,
			'mensagem' => 'Enviado por '.implode(' e ', $enviados).'.',
			'avisos' => $erros,
		]);
	}

	return json_encode(['erro' => $erros ? implode(' ', $erros) : 'Não foi possível enviar.']);
}

public static function reciboLote($request, $larguraPapel = null) {
	$id_admin = parent::getIdAdminInt();
	if ($larguraPapel === null) {
		$larguraPapel = self::formatoPapelFromRequest($request);
	}
	$ids = self::parseReciboIdsFromInput($request, $id_admin);
	if (!$ids) {
		return 'Nenhum título válido para impressão.';
	}
	if (count($ids) === 1) {
		return self::recibo($request, $ids[0], $larguraPapel);
	}

	$dadosLote = self::dadosReciboLote($ids, $id_admin);
	if (!$dadosLote) {
		return 'Nenhum título válido para impressão.';
	}
	$rows = $dadosLote['rows'];
	$total = $dadosLote['total'];
	$tipoPagamento = $dadosLote['tipoPagamento'];
	$dataPagamento = $dadosLote['dataPagamento'];

	$userLogedData = SessionUser::getUserLogedData();
	$escola = $userLogedData['escola'] ?? [];
	$logoUrl = htmlspecialchars(BrandingHelper::urlLogoEscola($escola['logo'] ?? null), ENT_QUOTES, 'UTF-8');
	$nomeEscola = htmlspecialchars((string)($escola['nome'] ?? 'Escola'), ENT_QUOTES, 'UTF-8');
	$cnpjEscola = htmlspecialchars((string)($escola['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8');
	$siteEscola = trim((string)($escola['site'] ?? ''));
	if ($siteEscola === '') {
		$siteEscola = 'www.ctieducacional.com.br';
	}
	$siteEscola = htmlspecialchars($siteEscola, ENT_QUOTES, 'UTF-8');

	$linhas = '';
	foreach ($rows as $r) {
		$linhas .= '
                <tr>
                    <td>'.htmlspecialchars(mb_substr($r['desc'], 0, 40), ENT_QUOTES, 'UTF-8').'</td>
                    <td class="right">R$ '.NumeroHelper::moedaBr($r['valor']).'</td>
                </tr>';
	}
	$idsLabel = htmlspecialchars(implode(', ', array_column($rows, 'id')), ENT_QUOTES, 'UTF-8');

	$isA4 = ($larguraPapel === 'a4');
	$pageCss = $isA4
		? '@page { size: A4; margin: 15mm; }'
		: '@page { size: 58mm auto; margin: 0; }';
	$bodyCss = $isA4
		? 'width: 100%; max-width: 180mm; margin: 0 auto; padding: 0;'
		: 'width: 48mm; margin: 0; padding: 0; overflow: hidden;';
	$fontCss = $isA4 ? '13px' : '11px';
	$logoCss = $isA4 ? 'max-width:120mm;max-height:35mm;' : 'max-width:42mm;max-height:18mm;';

	$reciboHtml = '
    <style>
    '.$pageCss.'
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; font-size: '.$fontCss.'; color: #000; }
    body { '.$bodyCss.' }
    .center { text-align: center; width: 100%; }
    .right { text-align: right; }
    .line { border-top: 1px dashed #000; margin: '.($isA4 ? '8px' : '4px').' 0; width: 100%; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td { padding: '.($isA4 ? '3px' : '1px').' 0; word-wrap: break-word; }
    .small { font-size: '.($isA4 ? '12px' : '10px').'; }
    strong { font-weight: bold; }
    </style>
    <body>
        <div class="center">
            <img src="'.$logoUrl.'" alt="" style="'.$logoCss.'object-fit:contain;"><br>
            <strong>'.$nomeEscola.'</strong><br>
            '.($cnpjEscola !== '' ? 'CNPJ: '.$cnpjEscola : '').'
        </div>
        <div class="line"></div>
        <div>
            Recibos: <strong>'.$idsLabel.'</strong><br>
            Data: '.DateTimeHelper::databr($dataPagamento).'<br>
            Hora: '.DateTimeHelper::extrairHorario($dataPagamento).'
        </div>
        <div class="line"></div>
        <table>
            <thead>
                <tr>
                    <th align="left">Item</th>
                    <th align="right">Valor</th>
                </tr>
            </thead>
            <tbody>'.$linhas.'
            </tbody>
        </table>
        <div class="line"></div>
        <table>
            <tr>
                <td><strong>Total</strong></td>
                <td class="right"><strong>R$ '.NumeroHelper::moedaBr($total).'</strong></td>
            </tr>
            <tr>
                <td class="small">Forma de Pgto:</td>
                <td class="right small">'.htmlspecialchars($tipoPagamento, ENT_QUOTES, 'UTF-8').'</td>
            </tr>
        </table>
        <div class="line"></div>
        <div class="small center">
            Referente a baixa de mensalidade(s).<br>
            Documento sem valor fiscal.
        </div>
        <div class="line"></div>
        <div class="center small">
            Obrigado pela preferência!<br>
            <strong>'.$siteEscola.'</strong>
        </div>
        <br>
        <div class="center">.</div>
    </body>';

	return View::render('admin/modules/carnes/recibo', [
		'title' => 'Comprovante de pagamento',
		'show-recibo' => $reciboHtml,
	]);
}


public static function registrarPagamento($request){

  $postVars = $request->getPostVars();

  $resposta = [
    "filtro" => $postVars['id_aluno']
  ];

$valor_recebido = str_replace(',', '.', $postVars['valor_recebido']);
$valor_recebido = filter_var($valor_recebido, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$valor_recebido = floatval($valor_recebido);

$valor_pagar = str_replace(',', '.', $postVars['valor_pagar']);
$valor_pagar = filter_var($valor_pagar, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$valor_pagar = floatval($valor_pagar);

  if($valor_recebido < $valor_pagar){
    $resposta ["erro"] = 'Valor recebido é menor que o valor a receber.';
    return json_encode($resposta);
  }
  if($postVars['tipo_pagamento'] == ''){
    $resposta ["erro"] = 'Selecione uma forma de pagamento.';
    return json_encode($resposta);
  }

  if($postVars['tipo_pagamento'] == ''){
    $resposta ["erro"] = 'Selecione uma forma de pagamento.';
    return json_encode($resposta);
  }

      //NOVA INSTANCIA
  $obCaixa = new Caixa;
  $obCaixa->id = $postVars['id'];
  $obCaixa->valor_pago = $valor_pagar;
  $obCaixa->data_pagamento = $postVars['data_pagamento'] ?? '';
  $obCaixa->tipo_pagamento = $postVars['tipo_pagamento'] ?? '';
  $obCaixa->status = FinanceiroAlunoHelper::STATUS_PAGO;
  $obCaixa->atualizar();

  if(!$obCaixa){
    $resposta ["erro"] = 'Erro ao registrar o pagamento';
  }

  return json_encode($resposta);

}


  public static function imprimeCarne($request,$id){

    $id = (int)$id;
    $id_admin = parent::getIdAdminInt();

    if (!TenantHelper::pertenceMatricula($id, $id_admin)) {
      return 'Matrícula não encontrada.';
    }

    // DADOS DA EMPRESA
    $userLogedData = SessionUser::getUserLogedData();
    // DADOS DO CONTRATO
    $obMatricula = (array) EntityMatri::getMatriculaById($id);
    // DADOS DO CLIENTE
    $obAluno = EntityUser::getUserById($obMatricula['id_aluno'] ?? 0);
    $dadosUser = $obAluno ? (array) $obAluno : [];
    $nomeAluno = (string)($dadosUser['nome'] ?? 'Aluno não encontrado');
    // DADOS DA TRILHA
    $obTrilhaObj = EntityTrilhas::getTrilhaById($obMatricula['id_trilha'] ?? 0);
    $obTrilha = $obTrilhaObj ? (array) $obTrilhaObj : [];

    // DADOS DO RESPONSÁVEL FINANCEIRO (tabela responsaveis, não usuarios)
    $responsavelFinanceiro = $nomeAluno;
    $idResponsavel = (int)($obMatricula['id_responsavel'] ?? 0);
    if ($idResponsavel > 0) {
      $obResponsavel = EntityRes::getResById($idResponsavel);
      if ($obResponsavel && !empty($obResponsavel->nome)) {
        $responsavelFinanceiro = (string)$obResponsavel->nome;
      }
    }

    $total = NumeroHelper::moedaBr(($obMatricula['qtd_parcelas'] ?? 0) * ($obMatricula['valor'] ?? 0));

    $count = 1;
    $carne = '';
    $qrCode = '';

      // VERIFICA SE TEM DESCONTO PONTUALIDADE
    $obs = ($obMatricula['desconto_pontualidade'] ?? false) ? '<b class="tag">Obs: 10% de desconto ao pagar até o dia do vencimento</b>' : 'Observações';

    $results = Caixa::getCaixa("id_ref =".$obMatricula['id']);

    while ($obCaixa = $results->fetchObject(Caixa::class)) {

        // VERIFICA SE EXISTE QRCODE PIX
      if(!empty($obCaixa->pix_copia_cola)){
        $pixPayload = rawurlencode((string)$obCaixa->pix_copia_cola);
        $qrCode = '<tr>
        <td rowspan="4">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=130x130&data='.$pixPayload.'" alt="PIX">
        </td>
        </tr>';
      }

      $carne .= '

      <div class="espaco"></div>
      <!-- Div que envolve todo o carne -->

      <div class="parcela">

      <!-- Canhoto do carne -->
      <div class="destaca">
      <table>
      <tr>
      <td class="tag"><b>Nº Lançamento</b>
      <br>
      <span>'.$obMatricula['id'].'</span>
      </td>
      <td class="tag"><b>Parcela</b>
      <br>
      <span>'.$count.' / '.$obMatricula['qtd_parcelas'].'</span>
      </td>
      </tr>
      <tr>
      <td colspan="2" class="tag"><b>'.htmlspecialchars((string)($userLogedData['escola']['nome'] ?? ''), ENT_QUOTES, 'UTF-8').'</b>
      <br>
      <span>CNPJ: '.htmlspecialchars((string)($userLogedData['escola']['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>
      </td>
      </tr>
      <tr>
      <td class="tag"><b>Aluno</b>
      <br>
      <span>'.htmlspecialchars($nomeAluno, ENT_QUOTES, 'UTF-8').'</span>
      </td>
      <td class="tag"><b>Valor Total</b>
      <br>
      <span>R$ '.$total.'</span>
      </td>
      </tr>
      <tr>
      <td class="tag"><b>Valor Parcela</b>
      <br>
      <span>R$ '.NumeroHelper::moedaBr($obCaixa->valor).'</span>
      </td>
      <td class="tag"><b>Vencimento</b>
      <br>
      <span>'.DateTimeHelper::databr($obCaixa->vencimento).'</span>
      </td>
      </tr>
      <tr>
      <td style="text-align: center;" colspan="2" class="botton">
      <b class="tag">Assinatura Secretaria</b><br>
      </td>
      </tr>
      </table>
      </div>

      <!-- Parte com qr code do carne -->

      <table>

      '.$qrCode.'

      <tr>
      <td colspan="1" class="tag"><b>Produto/Serviço</b>
      <br>
      <span>'.htmlspecialchars((string)($obTrilha['nome'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>
      </td>
      <td class="tag"><b>Valor Total</b>
      <br>
      <span>R$ '.$total.'</span>
      </td>
      </tr>

      <tr>
      <td colspan="1" class="tag"><b>Aluno</b>
      <br>
      <span>'.htmlspecialchars($nomeAluno, ENT_QUOTES, 'UTF-8').'</span>
      </td>
      <!--
      <td class="tag"><b>Responssável fianceito</b>
      <br>
      <span>'.$responsavelFinanceiro.'</span>
      </td>
      -->
      </tr>

      <tr>
      <td colspan="1" class="tag"><b>Valor Parcela</b>
      <br>
      <span>R$ '.NumeroHelper::moedaBr($obCaixa->valor).'</span>
      </td>
      <td class="tag"><b>Vencimento</b>
      <br>
      <span>'.DateTimeHelper::databr($obCaixa->vencimento).'</span>
      </td>
      </tr>
      <tr>
      <td colspan="2" class="botton">
      '.$obs.'
      <span></span><br>
      </td>
      </tr>
      </td>

      </table>
      </div>
      <div class="linha"></div>

      ';

      $count++;
    }

         //CONTEÚDO DE FORMULÁRIO
    $content = View::render('admin/modules/carnes/carne',[
     'title' => 'Carnê de pagamento',
     'show-carne' => $carne,

   ]);

    return $content;

  }


  }