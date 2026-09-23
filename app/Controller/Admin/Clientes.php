<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\User as EntityUser;
use \App\Model\Entity\Responsaveis as EntityRes;
use \App\Model\Entity\EstadoCidades;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\EmailValidator;
use \App\Common\Helpers\UserFotoHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\MatriculaStatusHelper;
use \App\Common\Helpers\ConectIdadeHelper;
use \App\Model\Entity\AlunoObservacao;
use \App\Session\User\Login as SessionUser;

class Clientes extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/cliente/index',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Alunos',$content,'users');
	}

	private static function getUserItems($request,&$obPagination){

//DADOS DO ADMIN
    $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

    //PAGINA ATUAL
		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;

    $id_cliente = (isset($postVars['filtro']) && $postVars['filtro'] !== '' && $postVars['filtro'] !== null && (int)$postVars['filtro'] > 0)
		? (int)$postVars['filtro'] : 0;
	$busca = trim((string)($postVars['busca'] ?? ''));
	$ativo = trim((string)($postVars['ativo'] ?? ''));
	$comMatricula = trim((string)($postVars['matricula'] ?? ''));

    $where = "nivel = 'Cliente' AND id_admin = ".(int)$id_admin;
	if ($id_cliente > 0) {
		$where .= ' AND id = '.$id_cliente;
	}
	$termo = TenantHelper::termoLike($busca);
	if ($termo !== '') {
		$like = '\'%'.$termo.'%\'';
		$where .= ' AND (nome LIKE '.$like.' OR email LIKE '.$like.' OR whatsapp LIKE '.$like.')';
	}
	if ($ativo === 's' || $ativo === 'n') {
		$where .= ' AND ativo = "'.$ativo.'"';
	}
	if ($comMatricula === 'ativa') {
		$where .= ' AND id IN (SELECT id_aluno FROM matriculas WHERE id_admin = '.(int)$id_admin
			.' AND '.MatriculaStatusHelper::sqlAtiva('matriculas').')';
	} elseif ($comMatricula === 'sem') {
		$where .= ' AND id NOT IN (SELECT id_aluno FROM matriculas WHERE id_admin = '.(int)$id_admin
			.' AND '.MatriculaStatusHelper::sqlAtiva('matriculas').')';
	}

$itens = '';

// QUANTIDADE TOTAL DE REGISTROS
$quantidadeTotal = (int)(EntityUser::getUser($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd ?? 0);

// INSTANCIA DE PAGINAÇÃO
$obPagination = new Pagination($quantidadeTotal, $paginaAtual, 10);

// RESULTADOS DA PAGINA
$results = EntityUser::getUser($where, 'nome ASC', $obPagination->getLimit());

		$userLoged = SessionUser::getUserLogedData();
		$idAdminGate = (int)($userLoged['usuario']['id_admin'] ?? 0);
		$modsEfetivos = ModuleGateHelper::getModulosEfetivos($idAdminGate, $userLoged['usuario']['acesso'] ?? []);
		$podeProgressoEad = in_array('Cursos Online', $modsEfetivos, true);

		//REDERIZA O ITEM
		while ($obUsers = $results->fetchObject(EntityUser::class)) {
			$badgeAtivo = (($obUsers->ativo ?? '') === 's')
				? '<span class="badge bg-success">Ativo</span>'
				: '<span class="badge bg-secondary">Inativo</span>';
			$idade = ConectIdadeHelper::calcularIdade($obUsers->nascimento ?? null);
			if ($idade !== null) {
				$idadeHtml = (int)$idade.' anos';
				if (ConectIdadeHelper::isMenor($idade)) {
					$idadeHtml .= ' <span class="badge bg-warning text-dark ms-1">Menor</span>';
				}
			} else {
				$idadeHtml = '<span class="text-muted">—</span>';
			}
			$linkProgressoEad = $podeProgressoEad
				? '<li>
			<a class="dropdown-item" href="'.URL.'/painel/ead/aluno/'.$obUsers->id.'"><i class="fa-solid fa-graduation-cap fa-lg"></i> Progresso EAD</a>
			</li>'
				: '';
			$linkExtrato = '<li>
			<a class="dropdown-item" href="'.URL.'/painel/alunos/'.$obUsers->id.'/extrato"><i class="fa-solid fa-file-invoice-dollar fa-lg"></i> Extrato financeiro</a>
			</li>';
			$itens .= '<tr>
			<td>'.htmlspecialchars((string)$obUsers->nome, ENT_QUOTES, 'UTF-8').'<div class="small mt-1">'.$badgeAtivo.'</div></td>
			<td>'.$idadeHtml.'</td>
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
			<a class="dropdown-item" href="#" onclick="anotacoesAluno('.$obUsers->id.')"><i class="fa-regular fa-note-sticky fa-lg"></i> Anotações</a>
			</li>
			'.$linkProgressoEad.'
			'.$linkExtrato.'
			<li>
			<a class="dropdown-item" href="#" onclick=\'iniciarAtendimentoWa('.json_encode((string)$obUsers->whatsapp).', '.json_encode((string)$obUsers->nome).')\'><i class="fa-brands fa-whatsapp fa-lg text-success"></i> Atendimento WhatsApp</a>
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

		if ($itens === '') {
			$itens = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhum aluno encontrado com esses filtros.</td></tr>';
		}

		$table = '<div class="card-body">
		<div class="table-responsive">
		<table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
		<thead>
		<tr>
		<th>Nome</th>
		<th>Idade</th>
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
			'itens' => self::getUserItems($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);

	}


	private static function getForm($request) {

		$postVars = $request->getPostVars();

		if ($postVars['funcao'] == 'editar') {
			$id = (int)($postVars['id'] ?? 0);
			$id_admin = parent::getIdAdminInt();
			if (!TenantHelper::pertenceUsuario($id, $id_admin, 'Cliente')) {
				return json_encode(['erro' => 'Registro não encontrado.']);
			}
			$dados = (array) EntityUser::getUserById($id);
		}

		$acesso = array();
		$acesso = json_decode(@$dados['acesso'] == '' ? '[0]' : @$dados['acesso']);
		
		if(@$dados['email'] == ''){
		    @$dados['email'] = 'sem@email.com';
		}

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

		$id_admin = parent::getIdAdmin()['usuario']['id_admin']; 

		$disabledResSelect = 'disabled';

		   
    // Carrega o SELECT
    $optResSelect = '<option value="">Selecione</option>';

    $nascimentoAluno = @$dados['nascimento'];
	$diferencaIdade = DateTimeHelper::subtrairDatas($nascimentoAluno,date("Y-m-d"));

	$resultREsponsavel = EntityRes::getRes('id_admin = ' . (int)$id_admin, 'id ASC',);

	if($diferencaIdade->y < 18){
	

		$disabledResSelect ='';

    while ($dadosResponsavel = $resultREsponsavel->fetchObject(EntityRes::class)) {
        $selected = (isset($dados['id_responsavel']) && $dados['id_responsavel'] == $dadosResponsavel->id) ? 'selected' : '';
        $optResSelect .= '
            <option ' . $selected . ' value="' . htmlspecialchars($dadosResponsavel->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($dadosResponsavel->nome, ENT_QUOTES, 'UTF-8') . '</option>
        ';
    }
  
} 

		// COMEÇA O FORM
		$form = '<form id="form" method="post" enctype="multipart/form-data">

		<!-- HEADER -->
		<div class="modal-header">
		<h1 class="modal-title fs-5" id="exampleModalLabel">Alunos</h1>
		<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		</div>

		<!-- BODY -->
		<div class="modal-body">
		<div id="response"></div>

		<!-- INICIA A ROW PRINCIPAL -->
		<div class="row">
		'.UserFotoHelper::htmlCampoFormulario(@$dados['foto'] ?? null, 'input-foto-aluno').'

		<div class="form-group col-md-4">
		<label>Nome</label>
		<input type="text" name="nome" value="' . @$dados['nome'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-4">
		<label>Whatsapp</label>
		<input type="text" name="whatsapp" value="' . @$dados['whatsapp']. '" class="form-control mascara-celular"  required>
		</div>

		<div class="form-group col-md-4">
		<label>Email</label>
		<input type="email" name="email" value="' . @$dados['email'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-4">
		<label>Nascimento</label>
		<input type="date" name="nascimento" value="' . @$dados['nascimento'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-4">
		<label>RG</label>
		<input type="text" name="rg" value="' . @$dados['rg'] . '" class="form-control mascara-rg" required>
		</div>

		<div class="form-group col-md-4">
		<label>CPF</label>
		<input type="text" name="cpf" value="' . @$dados['cpf'] . '" class="form-control mascara-cpf" required>
		</div>


		<div class="form-group col-md-5">
		<label>Endereço</label>
		<input type="text" name="endereco" value="' . @$dados['endereco'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-2">
		<label>Número</label>
		<input type="text" name="numero" value="' . @$dados['numero'] . '" class="form-control" required>
		</div>

		<div class="form-group col-md-5">
		<label>Bairro</label>
		<input type="text" name="bairro" value="' . @$dados['bairro'] . '" class="form-control" required>
		</div>


		<div class="form-group col-md-3">
		<label>Estado</label>

		' . $optEstadoSelect . '
		</div>

		<div class="form-group col-md-3">
		<label>Cidade</label>
		<div id="cidades"></div>
		</div>

		<div class="col-md-2">
		<label>Ativo</label>
		<select class="form-control" name="ativo">
		<option ' . (@$dados['ativo'] == 's' ? 'selected' : '') . ' value="s" >Sim</option>
		<option ' . (@$dados['ativo'] == 'n' ? 'selected' : '') . ' value="n" value="n">Não</option>
		</select>
		</div>

		<div class="form-group col-md-4">
		<label>Responsável</label>
            <select class="form-control" '.$disabledResSelect.' name="responsavel">
                ' . $optResSelect . '
            </select> 
		</div>

		
		<!-- FIM DA ROW PRINCIPAL -->
		</div>

		<!-- FIM DO BODY -->
		</div>

		<!-- FOOTER -->

		<div class="modal-footer">
		<input value="' . @$dados['id'] . '" type="hidden" name="id">
		<input value="' . @$dados['email'] . '" type="hidden" name="email_antigo">
		<button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
		<button type="submit" class="btn btn-primary">Salvar</button>
		</div>
		<script src="'.URL.'/resources/js/js_mascara.js"></script>
		<!-- TERMINA O FORM -->
		</form>';
 

     //JUNTA OS DADOS EM UM ARRAY SÓ
		$dadosCompletos = [
			'form' => $form,
			'cidade' => (int)@$dados['cidade']
		];
		return $dadosCompletos;
	}


	public static function getNewUser($request){
	    

		$form = self::getForm($request);
		return json_encode($form);
	}

	public static function setNewUser($request){


		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		$postVars = $request->getPostVars();
		$fileVars = $request->getFileVars();
	

		$permission = array();
    	$permission[]='Meus cursos'; 
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
    $endereco = filter_var($postVars['endereco'] ?? '', FILTER_SANITIZE_STRING); 
    $numero = filter_var($postVars['numero'] ?? '', FILTER_SANITIZE_STRING);
    $bairro = filter_var($postVars['bairro'] ?? '', FILTER_SANITIZE_STRING);
    $estado = filter_var($postVars['estado'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
    $cidade = filter_var($postVars['cidade'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
    $ativo = filter_var($postVars['ativo'] ?? 'n', FILTER_SANITIZE_STRING);

	$erroEmail = EmailValidator::mensagemErro($email, false);
	if ($erroEmail !== null) {
		$resposta['erro'] = $erroEmail;
		return json_encode($resposta);
	}

    // VERIFICAÇÃO SE EXISTE UM EMAIL ANTIGO 
	
	$email_antigo = EmailValidator::normalizar($postVars['email_antigo'] ?? '');

	if($email !== '' && $email_antigo != '' AND $email_antigo != $email){

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

		if($postVars['id'] != ''){

			$id = (int)$postVars['id'];
			$id_admin = parent::getIdAdminInt();

			if (!TenantHelper::pertenceUsuario($id, $id_admin, 'Cliente')) {
				$resposta['erro'] = 'Registro não encontrado.';
				return json_encode($resposta);
			}

			$resposta ["filtro"] = $postVars['id'];

			//NOVA INSTANCIA
			$obUsers = new EntityUser;
			$obUsers->id = $postVars['id'];
			$obUsers->nome = $nome;
			$obUsers->email = $email;
			$obUsers->nivel = 'Cliente';
			$obUsers->id_responsavel = $postVars['responsavel'] ?? 0;
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
			$obUsers->atualizar();

		} else {

			if ($email !== '') {
				$obUser = EntityUser::getUserByEmail($email);
				if ($obUser instanceof EntityUser) {
					$resposta['erro'] = 'Esse email já está cadastrado.';
					return json_encode($resposta);
				}
			}

		//NOVA INSTANCIA
			$obUsers = new EntityUser;
			$obUsers->nome = $nome;
			$obUsers->email = $email;
			$obUsers->nivel = 'Cliente';
			$obUsers->id_responsavel = $postVars['responsavel'] ?? 0;
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

		if (!TenantHelper::pertenceUsuario($id, $id_admin, 'Cliente')) {
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

	/** Listar / salvar anotações do aluno (modal). */
	public static function anotacoes($request) {
		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? 'form');
		$idAdmin = parent::getIdAdminInt();
		$alunoId = (int)($post['id'] ?? $post['aluno_id'] ?? 0);

		if (!TenantHelper::pertenceUsuario($alunoId, $idAdmin, 'Cliente')) {
			return json_encode(['success' => false, 'message' => 'Aluno não encontrado.']);
		}

		if (!AlunoObservacao::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL da tabela aluno_observacoes (database/aluno_observacoes.sql).',
			]);
		}

		if ($acao === 'salvar') {
			$texto = trim((string)($post['observacao'] ?? ''));
			if ($texto === '') {
				return json_encode(['success' => false, 'message' => 'Informe a observação.']);
			}
			$user = SessionUser::getUserLogedData();
			$ob = new AlunoObservacao;
			$ob->id_admin = $idAdmin;
			$ob->aluno_id = $alunoId;
			$ob->usuario_id = (int)($user['usuario']['id'] ?? 0);
			$ob->observacao = $texto;
			$ob->cadastrar();
			return json_encode([
				'success' => true,
				'message' => 'Observação salva.',
				'html'    => self::htmlListaObservacoes($alunoId, $idAdmin),
			]);
		}

		$aluno = EntityUser::getUserById($alunoId);
		$nome = ($aluno instanceof EntityUser) ? (string)$aluno->nome : 'Aluno';

		$html = '
		<div class="modal-header">
			<h5 class="modal-title">Anotações — '.htmlspecialchars($nome, ENT_QUOTES, 'UTF-8').'</h5>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
		</div>
		<div class="modal-body">
			<div id="aluno-obs-alert"></div>
			<div class="mb-3">
				<label class="form-label" for="aluno-obs-texto">Nova observação</label>
				<textarea class="form-control" id="aluno-obs-texto" rows="3" placeholder="Escreva aqui..."></textarea>
			</div>
			<button type="button" class="btn btn-primary btn-sm mb-3" id="btn-salvar-aluno-obs" data-aluno="'.$alunoId.'">
				<i class="fas fa-plus"></i> Adicionar
			</button>
			<hr>
			<div id="aluno-obs-lista">'.self::htmlListaObservacoes($alunoId, $idAdmin).'</div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
		</div>';

		return json_encode(['success' => true, 'html' => $html]);
	}

	private static function htmlListaObservacoes(int $alunoId, int $idAdmin): string {
		$results = AlunoObservacao::get(
			'aluno_id = '.$alunoId.' AND id_admin = '.$idAdmin,
			'criado_em DESC',
			'100'
		);
		$itens = '';
		while ($ob = $results->fetchObject(AlunoObservacao::class)) {
			$autor = EntityUser::getUserById((int)$ob->usuario_id);
			$nomeAutor = ($autor instanceof EntityUser) ? $autor->nome : 'Usuário';
			$data = !empty($ob->criado_em) ? date('d/m/Y H:i', strtotime($ob->criado_em)) : '';
			$itens .= '
			<div class="border rounded p-2 mb-2 bg-light">
				<div class="d-flex justify-content-between small text-muted mb-1">
					<span><i class="fas fa-user"></i> '.htmlspecialchars((string)$nomeAutor, ENT_QUOTES, 'UTF-8').'</span>
					<span>'.htmlspecialchars($data, ENT_QUOTES, 'UTF-8').'</span>
				</div>
				<div>'.nl2br(htmlspecialchars((string)$ob->observacao, ENT_QUOTES, 'UTF-8')).'</div>
			</div>';
		}
		if ($itens === '') {
			return '<p class="text-muted small mb-0">Nenhuma observação ainda.</p>';
		}
		return $itens;
	}

}