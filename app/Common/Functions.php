<?php

namespace App\Common;
use \App\Model\Entity\EstadoCidades;
use \App\Session\User\Login as SessionUser;
use \App\Model\Entity\User as EntityUser;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\EmailValidator;

class Functions{

		public static function getCidades($request){
		$postVars = $request->getPostVars();
		$results = EstadoCidades::getCidades('estados_id = "' .$postVars['estado']. '"');

    // Carrega o SELECT
		$optSelectCidade = '<select class="form-control"  id="cidade" name="cidade">';

		while ($obDados = $results->fetchObject(EstadoCidades::class)) {
			$selected = (isset($postVars['cidade']) && $postVars['cidade'] == $obDados->id) ? 'selected' : '';
			$optSelectCidade .= '
			<option ' . $selected . ' value="' . htmlspecialchars($obDados->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($obDados->nome, ENT_QUOTES, 'UTF-8') . '</option>
			';
		}
		$optSelectCidade .= '</select>';

		return $optSelectCidade;

	} 



	//RETORNA A RENDERIZAÇÃO DA PÁGINA DE LOGIN
	public static function formProfile(){

		$userLogedData = SessionUser::getUserLogedData();

		$id_user = $userLogedData['usuario']['id'];
		$id_admin = $userLogedData['usuario']['id_admin'];

		$dados = (array) EntityUser::getUserById($id_user);

		$acesso = array();
		$acesso = json_decode($dados['acesso'] == '' ? '[0]' : @$dados['acesso']);
		
		$results = EstadoCidades::getEstados();

    // Carrega o SELECT
		$optEstadoSelect = '<select class="form-control" onchange="selectEstado('.(int)@$dados['cidade'].')" id="estado" name="estado">';

		while ($obDados = $results->fetchObject(EstadoCidades::class)) {
			$selected = (isset($dados['uf']) && $dados['uf'] == $obDados->id) ? 'selected' : '';
			$optEstadoSelect .= '
			<option ' . $selected . ' value="' . htmlspecialchars($obDados->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($obDados->nome, ENT_QUOTES, 'UTF-8') . '</option>
			';
		}
		$optEstadoSelect .= '</select>';

	
		// COMEÇA O FORM
		$form = '<form id="formPerfil" method="post">

		<!-- HEADER -->
		<div class="modal-header">
		<h1 class="modal-title fs-5" id="exampleModalLabel">Dados do Usuário</h1>
		<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		</div>

		<!-- BODY -->
		<div class="modal-body">
		<div id="response"></div>

		<!-- INICIA A ROW PRINCIPAL -->
		<div class="row">

		<div class="form-group col-md-6">
		<label>Nome</label>
		<input type="text" name="nome" value="' . $dados['nome'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-6">
		<label>Email</label>
		<input type="email" name="email" value="' . $dados['email'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-6">
		<label>Whatsapp</label>
		<input type="text" name="whatsapp" value="' . $dados['whatsapp'] . '" class="form-control mascara-celular"  required>
		</div>

		<div class="form-group col-md-6">
		<label>CPF</label>
		<input type="text" name="cpf" value="' . $dados['cpf'] . '" class="form-control mascara-cpf"  required>
		</div>

		<div class="form-group col-md-6">
		<label>Nascimento</label>
		<input type="date" name="nascimento" value="' . $dados['nascimento'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-6">
		<label>RG</label>
		<input type="text" name="rg" value="' . $dados['rg']. '" class="form-control mascara-rg"  required>
		</div>

		<div class="form-group col-md-9">
		<label>Endereço</label>
		<input type="text" name="endereco" value="' . $dados['endereco'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-3">
		<label>Número</label>
		<input type="text" name="numero" value="' . $dados['numero'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-4">
		<label>Bairro</label>
		<input type="text" name="bairro" value="' . $dados['bairro'] . '" class="form-control" required>
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

		<!-- FIM DO BODY -->
		</div>

		<!-- FOOTER -->

		<div class="modal-footer">
		<input value="' . $dados['id'] . '" type="hidden" name="id">
		<input value="' . $dados['email'] . '" type="hidden" name="email_antigo">
		<button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
		<button type="submit" class="btn btn-primary">Salvar</button>
		</div>

		<script src="'.URL.'/resources/js/js_mascara.js"></script>

		<!-- TERMINA O FORM -->

		</form>';
 

		 //JUNTA OS DADOS EM UM ARRAY SÓ
		$dadosCompletos = [
			'form' => $form,
			'cidade' => (int)$dados['cidade']
		];

		return json_encode($dadosCompletos);



	}


	public static function saveProfile($request){

		$userLogedData = SessionUser::getUserLogedData();
		$id_admin = $userLogedData['usuario']['id_admin'];

		$postVars = $request->getPostVars();
		$resposta = array();


// Sanitização dos campos utilizando funções nativas do PHP
    $nome = filter_var($postVars['nome'] ?? '', FILTER_SANITIZE_STRING);
    $email = EmailValidator::normalizar($postVars['email'] ?? '');
    $whatsapp = filter_var($postVars['whatsapp'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
    $rg = filter_var($postVars['rg'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
    $cpf = filter_var($postVars['cpf'] ?? '', FILTER_SANITIZE_NUMBER_INT); 
    $endereco = filter_var($postVars['endereco'] ?? '', FILTER_SANITIZE_STRING); 
    $numero = filter_var($postVars['numero'] ?? '', FILTER_SANITIZE_STRING);
    $bairro = filter_var($postVars['bairro'] ?? '', FILTER_SANITIZE_STRING);
    $estado = filter_var($postVars['estado'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
    $cidade = filter_var($postVars['cidade'] ?? 0, FILTER_SANITIZE_NUMBER_INT);

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

			//NOVA INSTANCIA
			$obUsers = new EntityUser;
			$obUsers->id = (int)$userLogedData['usuario']['id'];
			$obUsers->nome = $nome;
			$obUsers->email = $email;
			$obUsers->whatsapp = $whatsapp;
			$obUsers->rg = $rg;
			$obUsers->cpf = $cpf;
			$obUsers->nascimento = $postVars['nascimento'] ?? '';
			$obUsers->endereco = $endereco;
			$obUsers->numero = $numero;
			$obUsers->bairro = $bairro;
			$obUsers->uf = $estado;
			$obUsers->cidade = $cidade;
			$obUsers->atualizaPerfil();


		if(!$obUsers){
			$resposta ["erro"] = 'Erro ao cadastrar usuário';
		}
		return json_encode($resposta);

	}


	

}