<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\User as EntityUser; 
use \App\Model\Entity\EstadoCidades;
use \App\Model\Db\Pagination;
use \App\Session\User\Login as SessionUser;
use \App\Common\SystemModules;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\EmailValidator;
use \App\Common\Helpers\UserFotoHelper;

class User extends Page{

	/** Cargos exibidos na tela Funcionários (nunca alunos/leads/candidatos). */
	private static function sqlNiveisFuncionario(): string {
		$niveis = ['Diretor', 'Secretario', 'Financeiro', 'Comercial'];
		$quoted = array_map(static function ($n) {
			return '"'.addslashes($n).'"';
		}, $niveis);
		return 'nivel IN ('.implode(',', $quoted).')';
	}

	//RETORNA O FORMULARIO DE UM NOVO DEPOIMENTO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/user/index',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Funcionários',$content,'users');
	}

	private static function getUserItems($request,&$obPagination){
		
		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		//PAGINA ATUAL
		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;

		// Obtenção do filtro com valor padrão 'Aluno' se não definido
		$filtro = $postVars['filtro'] ?? null;

		$nivelSql = self::sqlNiveisFuncionario();

		if ($filtro) {
			if ($filtro == 'inativo') {
				$where = 'id_admin = "'.$id_admin.'" AND ativo = "n" AND '.$nivelSql;
			} else {
				$filtro = addslashes($filtro);
				$where = 'id_admin = "'.$id_admin.'" AND ativo = "s" AND nivel = "'.$filtro.'" AND '.$nivelSql;
			}
		} else {
			$where = 'id_admin = "'.$id_admin.'" AND ativo = "s" AND '.$nivelSql;
		}

		// Não listar operadores do Painel Master na escola
		$emailsMaster = \App\Common\Helpers\MasterGateHelper::emailsPermitidos();
		foreach ($emailsMaster as $em) {
			$where .= ' AND email != "'.addslashes($em).'"';
		}

		$itens = '';

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = EntityUser::getUser($where,null,null,'COUNT(*) as qtd')->fetchObject()->qtd;

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,5);

		//RESULTADOS DA PAGINA
		$results = EntityUser::getUser($where,'nome ASC', $obPagination->getLimit());

		//REDERIZA O ITEM
		while ($obUsers = $results->fetchObject(EntityUser::class)) {
			$itens .= '<tr>
			<td>'.$obUsers->nome.'</td>
			<td>'.$obUsers->email.'</td>
			<td>'.$obUsers->nivel.'</td>
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
			<a class="dropdown-item" href="#" onclick="resetSenha('.$obUsers->id.')"><i class="fa-solid fa-key fa-lg"></i> Resetar Senha</a>
			</li>
			<li>
			<a class="dropdown-item" href="#" onclick="excluir('.$obUsers->id.')" ><i class="far fa-trash-alt fa-lg"></i> Excluir</a>
			</li>
			</ul>
			</div>
			</td>
			</tr>';

		}


		$table = '<div class="card-body">
		<div class="table-responsive">
		<table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
		<thead>
		<tr>
		<th>Nome</th>
		<th>Email</th>
		<th>Cargo</th>
		<th>Ações</th>
		</tr>
		</thead>
		<tbody>'.$itens.'</tbody>
		</table>
		</div>
		</div>';

		//RETORNA OS USUÁRIOS
		return $table;
	}

	public static function getInfo($request){


//CONTEÚDO DE USUÁRIOS
		$conteudo = [
			'itens' => self::getUserItems($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);

	}

	

	public static function getAcessos($permissions,$acesso){

	$permissioes = ''; // Inicializando a variável

	foreach ($permissions as $permission){
		$permissionName = \App\Common\SystemModules::campoPermissao($permission);
		$checked = in_array($permission, $acesso, true) ? 'checked' : '';
		$permissioes .= 
		'<div class="form-group col-auto form-check">
		<input type="checkbox" ' . $checked . ' name="' . htmlspecialchars($permissionName, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . '" class="form-check-input">
		<label class="form-check-label">' . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . '</label>
		</div>';
	}

	return $permissioes; // Retorna o HTML gerado
}


private static function getForm($request) {
	$postVars = $request->getPostVars();
	$dados = [];

	if (isset($postVars['funcao']) && $postVars['funcao'] === 'editar') {
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();
		if (!TenantHelper::pertenceUsuario($id, $id_admin)) {
			return json_encode(['erro' => 'Registro não encontrado.']);
		}
		$dados = (array) EntityUser::getUserById($id);
	}

    // Decodifica o JSON das permissões, caso esteja vazio define como '[0]'
	$acesso = isset($dados['acesso']) && $dados['acesso'] !== '' ? json_decode($dados['acesso'], true) : [0];

	$id_admin = parent::getIdAdminInt();
	$modulosDisponiveis = ModuleGateHelper::getModulosDisponiveisParaEscola($id_admin);
	$avisoPlano = ModuleGateHelper::escolaTemTodosModulos($id_admin) ? '' :
		'<p class="text-muted small mb-2">Somente módulos liberados para esta escola.</p>';
	$avisoEad = '<p class="text-muted small mb-2"><strong>Cursos Online</strong> e <strong>Conquistas EAD</strong> são permissões separadas. Sem marcar, somem do menu (vale também para Diretor).</p>';

    // Obtém as permissões formatadas
	$permissoes = self::getAcessos($modulosDisponiveis, $acesso);

    // Carrega os estados para o select 
	$results = EstadoCidades::getEstados();
	$optEstadoSelect = '<select class="form-control" onchange="selectEstado('. (int) ($dados['cidade'] ?? 0) .')" id="estado" name="estado">';

	while ($obDados = $results->fetchObject(EstadoCidades::class)) {
		$selected = (isset($dados['uf']) && $dados['uf'] == $obDados->id) ? 'selected' : '';
		$optEstadoSelect .= '<option ' . $selected . ' value="' . htmlspecialchars($obDados->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($obDados->nome, ENT_QUOTES, 'UTF-8') . '</option>';
	}
	$optEstadoSelect .= '</select>';
 
    // Formulário HTML
	$form = '<form id="form" method="post" enctype="multipart/form-data">

	<!-- HEADER -->
	<div class="modal-header">
	<h1 class="modal-title fs-5" id="exampleModalLabel">Usuários</h1>
	<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	</div>

	<!-- BODY -->
	<div class="modal-body">
	<div id="response"></div>

	<!-- INICIA A ROW PRINCIPAL -->
	<div class="row">
	'.UserFotoHelper::htmlCampoFormulario($dados['foto'] ?? null, 'input-foto-funcionario').'

	<div class="form-group col-md-3">
	<label>Nome</label>
	<input type="text" name="nome" value="' . (@$dados['nome'] ?? '') . '" class="form-control" required>
	</div>

	<div class="form-group col-md-3">
	<label>Whatsapp</label>
	<input type="text" name="whatsapp" value="' . (@$dados['whatsapp'] ?? '') . '" class="form-control mascara-celular" required>
	</div> 

	<div class="form-group col-md-3">
	<label>Email</label>
	<input type="email" name="email" value="' . (@$dados['email'] ?? '') . '" class="form-control" required>
	</div>

	<div class="form-group col-md-3">
	<label>Nascimento</label>
	<input type="date" name="nascimento" value="' . (@$dados['nascimento'] ?? '') . '" class="form-control" required>
	</div>

	<div class="form-group col-md-3">
	<label>RG</label>
	<input type="text" name="rg" value="' . (@$dados['rg'] ?? ''). '" class="form-control mascara-rg" required>
	</div>

	<div class="form-group col-md-3">
	<label>CPF</label>
	<input type="text" name="cpf" value="' . (@$dados['cpf'] ?? ''). '" class="form-control mascara-cpf" required>
	</div>

	<div class="form-group col-md-4">
	<label>Endereço</label>
	<input type="text" name="endereco" value="' . (@$dados['endereco'] ?? '') . '" class="form-control" required>
	</div>

	<div class="form-group col-md-2">
	<label>Número</label>
	<input type="text" name="numero" value="' . (@$dados['numero'] ?? ''). '" class="form-control" required>
	</div>

	<div class="form-group col-md-4">
	<label>Bairro</label>
	<input type="text" name="bairro" value="' . (@$dados['bairro'] ). '" class="form-control" required>
	</div>

	<div class="form-group col-md-4">
	<label>Estado</label>
	' . $optEstadoSelect . '
	</div>

	<div class="form-group col-md-4">
	<label>Cidade</label>
	<div id="cidades"></div>
	</div>

	<!-- FIM DA ROW PRINCIPAL -->
	</div>

	<!-- ROW SEGUNDARIA -->
	<div class="row mt-2">

	<!-- GRUPO DA ESQUERDA -->
	<div class="col-md-3 col-sm-6">
	<label>Ativo</label>
	<select class="form-control" name="ativo">
	<option ' . ((isset($dados['ativo']) && $dados['ativo'] === 's') ? 'selected' : '') . ' value="s">Sim</option>
	<option ' . ((isset($dados['ativo']) && $dados['ativo'] === 'n') ? 'selected' : '') . ' value="n">Não</option>
	</select>

	<label>Cargo</label>
	<select name="nivel" class="form-select" aria-label="Default select example">
	<option value="">Selecione o nível</option>
	<option ' . ((isset($dados['nivel']) && $dados['nivel'] === 'Secretario') ? 'selected' : '') . ' value="Secretario">Secretário</option>
	<option ' . ((isset($dados['nivel']) && $dados['nivel'] === 'Financeiro') ? 'selected' : '') . ' value="Financeiro">Financeiro</option>
	<option ' . ((isset($dados['nivel']) && $dados['nivel'] === 'Comercial') ? 'selected' : '') . ' value="Comercial">Comercial</option>
	<option ' . ((isset($dados['nivel']) && $dados['nivel'] === 'Diretor') ? 'selected' : '') . ' value="Diretor">Diretor</option>
	</select>
	</div>

	<!-- GRUPO DA DIREITA -->
	<div class="pl-4 col-md-9 col-sm-6">
	<label>Permissões de acesso</label>
	'.$avisoPlano.'
	'.$avisoEad.'
	<div class="row">
	' . $permissoes . '
	</div>

	<!-- FIM DA ROW SEGUNDARIA -->
	</div>

	<!-- FIM DO BODY -->
	</div>

	<!-- FOOTER -->
	<div class="modal-footer">
	<input value="' . (@$dados['id'] ?? '') . '" type="hidden" name="id">
	<input value="' . (@$dados['email'] ?? '') . '" type="hidden" name="email_antigo">
	<button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
	<button type="submit" class="btn btn-primary">Salvar</button>
	</div>

	<!-- TERMINA O FORM -->
	<script src="'.URL.'/resources/js/js_mascara.js"></script>
	</form>';

    // Junta os dados em um array completo
	$dadosCompletos = [
		'form' => $form,
		'cidade' => (int)($dados['cidade'] ?? 0)
	];

	return $dadosCompletos;
}

public static function getNewUser($request){

	$form = self::getForm($request);
	return json_encode($form);
}



public static function setNewUser($request) {
    // DADOS DO ADMIN
	$id_admin = parent::getIdAdminInt();
	$postVars = $request->getPostVars();
	$fileVars = $request->getFileVars();

	$resposta = [
		"filtro" => null
	];

	$modulosDisponiveis = ModuleGateHelper::getModulosDisponiveisParaEscola($id_admin);

    // Lista de permissões (apenas módulos liberados para a escola)
	$permissionsList = [];
	foreach ($modulosDisponiveis as $permission) {
		$permissionName = \App\Common\SystemModules::campoPermissao($permission);
		// Compat: nomes antigos com espaço (PHP virava underscore) e slug novo
		$legacy = str_replace(' ', '_', lcfirst((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $permission)));
		if (isset($postVars[$permissionName])) {
			$permissionsList[] = $permission;
		} elseif ($legacy !== '' && isset($postVars[$legacy])) {
			$permissionsList[] = $permission;
		}
	}

	$permissionsList = ModuleGateHelper::sanitizarAcesso($id_admin, $permissionsList);

    // Caso nenhuma permissão seja adicionada
	if (empty($permissionsList)) {
		$permissionsList[] = '';
	}

    // Converte as permissões para JSON
	$acesso = json_encode($permissionsList);

    // Sanitização dos campos utilizando funções nativas do PHP
	$nome = filter_var($postVars['nome'] ?? '', FILTER_SANITIZE_STRING);
	$email = EmailValidator::normalizar($postVars['email'] ?? '');
	$nivel = filter_var($postVars['nivel'] ?? '', FILTER_SANITIZE_STRING);
	$whatsapp = filter_var($postVars['whatsapp'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
	$rg = filter_var($postVars['rg'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
	$cpf = filter_var($postVars['cpf'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
	$endereco = filter_var($postVars['endereco'] ?? '', FILTER_SANITIZE_STRING); 
	$bairro = filter_var($postVars['bairro'] ?? '', FILTER_SANITIZE_STRING); 
	$numero = filter_var($postVars['numero'] ?? '', FILTER_SANITIZE_STRING);
	$estado = filter_var($postVars['estado'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
	$cidade = filter_var($postVars['cidade'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
	$ativo = filter_var($postVars['ativo'] ?? 'n', FILTER_SANITIZE_STRING);

	$erroEmail = EmailValidator::mensagemErro($email, true);
	if ($erroEmail !== null) {
		$resposta['erro'] = $erroEmail;
		return json_encode($resposta);
	}

	// VERIFICAÇÃO SE EXISTE UM EMAIL ANTIGO 
	
	$email_antigo = EmailValidator::normalizar($postVars['email_antigo'] ?? '');

	if($email_antigo != '' AND $email_antigo != $email){

	//BUSCA O USUÁRIO PELO EMAIL
		$obUser = EntityUser::getUserByEmail($email);

		if($obUser instanceof EntityUser){
			$resposta ["erro"] = 'Esse email já está cadastrado.';
			return json_encode($resposta);
		}
	}


	$fotoAtual = $postVars['foto_atual'] ?? null;
	if (!empty($postVars['id'])) {
		$atual = EntityUser::getUserById((int)$postVars['id']);
		if ($atual instanceof EntityUser && !empty($atual->foto)) {
			$fotoAtual = $atual->foto;
		}
	}
	$foto = UserFotoHelper::processarUpload($fileVars['foto'] ?? null, $fotoAtual);

    // Adicionar ou atualizar o usuário
	if ($postVars['id'] != '') {

		$id = (int)$postVars['id'];
		$id_admin = parent::getIdAdminInt();

		if (!TenantHelper::pertenceUsuario($id, $id_admin)) {
			$resposta['erro'] = 'Registro não encontrado.';
			return json_encode($resposta);
		}

        // NOVA INSTANCIA
		$obUsers = new EntityUser;
		$obUsers->id = $id;
		$obUsers->nome = $nome;
		$obUsers->email = $email;
		$obUsers->nivel = $nivel;
		$obUsers->whatsapp = $whatsapp;
		$obUsers->rg = $rg;
		$obUsers->cpf = $cpf;
		$obUsers->nascimento = $postVars['nascimento'] ?? '';
		$obUsers->endereco = $endereco;
		$obUsers->bairro = $bairro;
		$obUsers->numero = $numero;
		$obUsers->uf = $estado;
		$obUsers->cidade = $cidade;
		$obUsers->ativo = $ativo;
		$obUsers->acesso = $acesso;
		$obUsers->foto = $foto;
		$obUsers->atualizar();

	} else {

		//BUSCA O USUÁRIO PELO EMAIL
		$obUser = EntityUser::getUserByEmail($email);

		if($obUser instanceof EntityUser){
			$resposta ["erro"] = 'Esse email já está cadastrado.';
			return json_encode($resposta);
		}


        // NOVA INSTANCIA
		$obUsers = new EntityUser;
		$obUsers->nome = $nome;
		$obUsers->email = $email;
		$obUsers->nivel = $nivel;
		$obUsers->senha = password_hash('12345678', PASSWORD_DEFAULT);
		$obUsers->whatsapp = $whatsapp;
		$obUsers->rg = $rg;
		$obUsers->cpf = $cpf;
		$obUsers->nascimento = $postVars['nascimento'] ?? '';
		$obUsers->endereco = $endereco;
		$obUsers->numero = $numero;
		$obUsers->bairro = $bairro;
		$obUsers->uf = $estado;
		$obUsers->cidade = $cidade;
		$obUsers->ativo = $ativo;
		$obUsers->acesso = $acesso;
		$obUsers->foto = $foto;
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

	if (!TenantHelper::pertenceUsuario($id, $id_admin)) {
		return 'Registro não encontrado.';
	}

		//NOVA INSTANCIA
	$obUsers = new EntityUser;
	$obUsers->id = $id;
	$obUsers->excluir();

	if($obUsers){
		return true;
	} else {
		return 'Erro ao excluir usuário';
	}

}

}