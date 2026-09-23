<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\Responsaveis as EntityRes;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\EmailValidator;

class Responsavel extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/responsavel/index',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Responsáveis',$content,'users');
	}

	private static function getResItems($request,&$obPagination){

//DADOS DO ADMIN
    $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

    //PAGINA ATUAL
		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;

    $id_cliente = (isset($postVars['filtro']) && $postVars['filtro'] !== '' && $postVars['filtro'] !== null && (int)$postVars['filtro'] > 0)
		? (int)$postVars['filtro'] : 0;
	$busca = trim((string)($postVars['busca'] ?? ''));

    $where = 'id_admin = '.(int)$id_admin;
	if ($id_cliente > 0) {
		$where .= ' AND id = '.$id_cliente;
	}
	$termo = TenantHelper::termoLike($busca);
	if ($termo !== '') {
		$like = '\'%'.$termo.'%\'';
		$where .= ' AND (nome LIKE '.$like.' OR email LIKE '.$like.' OR whatsapp LIKE '.$like.' OR cpf LIKE '.$like.')';
	}

$itens = '';

// QUANTIDADE TOTAL DE REGISTROS
$quantidadeTotal = (int)(EntityRes::getRes($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd ?? 0);

// INSTANCIA DE PAGINAÇÃO
$obPagination = new Pagination($quantidadeTotal, $paginaAtual, 10);

// RESULTADOS DA PAGINA
$results = EntityRes::getRes($where, 'nome ASC', $obPagination->getLimit());


		//REDERIZA O ITEM
		while ($obUsers = $results->fetchObject(EntityRes::class)) {
			$itens .= '<tr>
			<td>'.htmlspecialchars((string)$obUsers->nome, ENT_QUOTES, 'UTF-8').'</td>
			<td>'.htmlspecialchars((string)$obUsers->email, ENT_QUOTES, 'UTF-8').'</td>
			<td class="mascara-celular">'.htmlspecialchars((string)$obUsers->whatsapp, ENT_QUOTES, 'UTF-8').'</td>
			<td>
			<div class="dropdown">
			<button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
			<i class="far fa-edit fa-lg"></i>
			</button>
			<ul class="dropdown-menu">
			<li>
			<a class="dropdown-item" href="#" onclick="list_itens('.$obUsers->id.', \'editar\')"><i class="far fa-edit fa-lg"></i> Editar</a>
			</li>
			<li>
			<a class="dropdown-item" href="#" onclick=\'iniciarAtendimentoWa('.json_encode((string)$obUsers->whatsapp).', '.json_encode((string)$obUsers->nome).')\'><i class="fa-brands fa-whatsapp fa-lg text-success"></i> Atendimento WhatsApp</a>
			</li>
			<li>
			<a class="dropdown-item" href="#" onclick="excluir('.$obUsers->id.')" ><i class="far fa-trash-alt fa-lg"></i> Excluir</a>
			</li>
			</ul>
			</div>
			</td>
			</tr>';

		}

		if ($itens === '') {
			$itens = '<tr><td colspan="4" class="text-center text-muted py-4">Nenhum responsável encontrado com esses filtros.</td></tr>';
		}

		$table = '
		<div class="card-body">
		<div class="table-responsive">
		<table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
		<thead>
		<tr>
		<th>Nome</th>
		<th>Email</th>
		<th>Whatsapp</th>
		<th>Ações</th>
		</tr>
		</thead>
		<tbody>'.$itens.'</tbody>
		</table>
		</div>
		</div>
		<script src="'.URL.'/resources/js/js_mascara.js"></script>';

		//RETORNA OS USUÁRIOS
		return $table;
	}

	public static function getInfo($request){


	//CONTEÚDO DE USUÁRIOS
		$conteudo = [
			'itens' => self::getResItems($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);

	}


	private static function getForm($request) {

		$postVars = $request->getPostVars();

		if ($postVars['funcao'] == 'editar') {
			$id = (int)($postVars['id'] ?? 0);
			$id_admin = parent::getIdAdminInt();
			if (!TenantHelper::pertence('responsaveis', $id, $id_admin)) {
				return json_encode(['erro' => 'Registro não encontrado.']);
			}
			$dados = (array) EntityRes::getResById($id);
		}


		// COMEÇA O FORM
		$form = '<form id="form" method="post">

		<!-- HEADER -->
		<div class="modal-header">
		<h1 class="modal-title fs-5" id="exampleModalLabel">Responsável</h1>
		<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		</div>

		<!-- BODY -->
		<div class="modal-body">
		<div id="response"></div>

		<!-- INICIA A ROW PRINCIPAL -->
		<div class="row">

		<div class="form-group col-md-6">
		<label>Nome</label>
		<input type="text" name="nome" value="' . @$dados['nome'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-6">
		<label>Nascimento</label>
		<input type="date" name="nascimento" value="' . @$dados['nascimento'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-6">
		<label>RG</label>
		<input type="text" name="rg" value="' . @$dados['rg'] . '" class="form-control mascara-rg"  required>
		</div>

		<div class="form-group col-md-6">
		<label>CPF</label>
		<input type="text" name="cpf" value="' . @$dados['cpf'] . '" class="form-control mascara-cpf"  required>
		</div>

		<div class="form-group col-md-6">
		<label>Whatsapp</label>
		<input type="text" name="whatsapp" value="' . @$dados['whatsapp'] . '" class="form-control mascara-celular"  required>
		</div>

		<div class="form-group col-md-6">
		<label>Email</label>
		<input type="email" name="email" value="' . @$dados['email'] . '" class="form-control">
		</div>

		
		<!-- FIM DA ROW PRINCIPAL -->
		</div>

		<!-- FIM DO BODY -->
		</div>

		<!-- FOOTER -->

		<div class="modal-footer">
		<input value="' . @$dados['id'] . '" type="hidden" name="id">
		<button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
		<button type="submit" class="btn btn-primary">Salvar</button>
		</div>
		<script src="'.URL.'/resources/js/js_mascara.js"></script>
		<!-- TERMINA O FORM -->
		</form>';
 
		return $form;
	}


	public static function getNewUser($request){

		$form = self::getForm($request);
		return json_encode($form);
	}

	public static function setNewUser($request){


		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		$postVars = $request->getPostVars();

		$permission = array();

if (empty($permission)) {
    $permission[]='home';
} 

$acesso = json_encode($permission);

$resposta = [
			"filtro" => null
	];


// Sanitização dos campos utilizando funções nativas do PHP
    $nome = filter_var($postVars['nome'] ?? '', FILTER_SANITIZE_STRING);
    $email = EmailValidator::normalizar($postVars['email'] ?? '');
    $whatsapp = filter_var($postVars['whatsapp'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
    $rg = filter_var($postVars['rg'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
    $cpf = filter_var($postVars['cpf'] ?? '', FILTER_SANITIZE_NUMBER_INT); 

	$erroEmail = EmailValidator::mensagemErro($email, false);
	if ($erroEmail !== null) {
		$resposta['erro'] = $erroEmail;
		return json_encode($resposta);
	}

		if($postVars['id'] != ''){

			$id = (int)$postVars['id'];
			$id_admin = parent::getIdAdminInt();

			if (!TenantHelper::pertence('responsaveis', $id, $id_admin)) {
				$resposta['erro'] = 'Registro não encontrado.';
				return json_encode($resposta);
			}

			$resposta ["filtro"] = $postVars['id'];

			//NOVA INSTANCIA
			$obUsers = new EntityRes;
			$obUsers->id = $postVars['id'];
			$obUsers->nome = $nome;
			$obUsers->email = $email;
			$obUsers->whatsapp = $whatsapp;
			$obUsers->rg = $rg;
			$obUsers->cpf = $cpf;
			$obUsers->nascimento = $postVars['nascimento'] ?? '';
			$obUsers->atualizar();

		} else {


		//NOVA INSTANCIA
			$obUsers = new EntityRes;
			$obUsers->nome = $nome;
			$obUsers->email = $email;
			$obUsers->whatsapp = $whatsapp;
			$obUsers->rg = $rg;
			$obUsers->cpf = $cpf;
			$obUsers->nascimento = $postVars['nascimento'] ?? '';
			$obUsers->id_admin = $id_admin;
			$obUsers->cadastrar();
		}


		if(!$obUsers){
			$resposta ["erro"] = 'Erro ao cadastrar usuário';
		}


		return json_encode($resposta);
		
	}


	public static function deleteUser($request){

		$postVars = $request->getPostVars();
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if (!TenantHelper::pertence('responsaveis', $id, $id_admin)) {
			return 'Registro não encontrado.';
		}

		//NOVA INSTANCIA
		$obUsers = new EntityRes;
		$obUsers->id = $id;
		$obUsers->excluir();

		if($obUsers){
			return true;
		} else {
			return 'Erro ao excluir usuário';
		}
		
	}

}