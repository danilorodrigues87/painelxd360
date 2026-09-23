<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\CrmLeads as EntityCrmLeads;
use \App\Model\Entity\CrmFunis as EntityCrmFunis;
use \App\Model\Entity\CrmHistorico as EntityCrmHistorico;
use \App\Model\Entity\User as EntityUser;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\NumeroHelper;
use \App\Common\Helpers\PlanilhaHelper;
use \App\Common\Helpers\EmailValidator;
use \App\Common\Communication\WhatsappEscolaService;
use \App\Common\Helpers\CrmAutomacaoTemplateHelper;
use \App\Common\Helpers\CrmPessoaHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Model\Db\Database;
use \App\Model\Db\Pagination;

class CrmLeads extends Page{

	private static $statusPermitidos = [
		'novo',
		'em_atendimento',
		'matriculado',
		'perdido'
	];

	private static $labelsStatus = [
		'novo'           => 'Novo',
		'em_atendimento' => 'Em atendimento',
		'matriculado'    => 'Matriculado',
		'perdido'        => 'Perdido'
	];

	private static $motivosPerda = [
		'Preço Alto',
		'Horário Incompatível',
		'Não Respondeu',
		'Outros'
	];

	private static $origensPermitidas = [
		'Balcão',
		'Macrocaptação',
		'Instagram',
		'Facebook',
		'Site',
		'Google',
		'Indicação',
		'Panfleto',
		'Conecta Jovem',
		'Conect Jovem',
		'Outros'
	];

	private static $visibilidadesPermitidas = [
		'publico',
		'privado'
	];

	private static $niveisAdministrador = [
		'Diretor'
	];

	public static function index($request){
		$content = View::render('admin/modules/crm/index',[]);
		return parent::getPanel('Leads',$content,'CRM');
	}

	public static function getInfo($request){

		$postVars = $request->getPostVars();
		$acao = $postVars['acao'] ?? '';

		if($acao === 'listar_funis'){
			return self::getFunis($request);
		}

		if($acao === 'salvar_funil'){
			return self::cadastrarFunil($request);
		}

		if($acao === 'excluir_funil'){
			return self::excluirFunil($request);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = (int)$dadosUser['usuario']['id_admin'];
		$paginaAtual = $postVars['page'] ?? 1;
		$limite = (int)(getenv('PAGINATION_LIMIT') ?: 10);

		$funilAtivo = self::resolverFunilFiltro($postVars, $id_admin);

		$whereBase = self::montarWhereListagem($dadosUser);
		$whereFiltro = $whereBase.self::montarFiltroFunil($funilAtivo).self::montarFiltrosBusca($postVars);

		$colunas = [];
		$totaisPagina  = [];

		foreach(self::$statusPermitidos as $status){
			$colunas[$status] = [];
			$totaisPagina[$status]  = 0;
		}

		$agregados = self::agregarPorStatus($whereFiltro);
		$contagensGlobais = $agregados['contagens'];
		$totaisGlobaisRaw = $agregados['totais'];

		$quantidadeTotal = (int)EntityCrmLeads::getLeads($whereFiltro, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd;
		$obPagination = new Pagination($quantidadeTotal, $paginaAtual, $limite);

		$results = EntityCrmLeads::getLeads(
			$whereFiltro,
			'data_cadastro DESC',
			$obPagination->getLimit()
		);

		while ($obLead = $results->fetchObject(EntityCrmLeads::class)) {
			$status = in_array($obLead->status, self::$statusPermitidos) ? $obLead->status : 'novo';

			$valorEstimado = (float)($obLead->valor_estimado ?? 0);
			$totaisPagina[$status] += $valorEstimado;

			$ultimaAtualizacao = self::getUltimaAtualizacao($obLead->id, $obLead->data_cadastro);
			$horasSemContato   = self::horasDesde($ultimaAtualizacao);

			$colunas[$status][] = [
				'id'                 => $obLead->id,
				'nome'               => $obLead->nome,
				'whatsapp'           => $obLead->whatsapp,
				'curso_interesse'    => $obLead->curso_interesse,
				'valor_estimado'     => $valorEstimado,
				'valor_estimado_br'  => $valorEstimado > 0 ? NumeroHelper::moedaBr($valorEstimado) : '',
				'data_cadastro'      => DateTimeHelper::databr($obLead->data_cadastro),
				'status_wa'          => $obLead->status_wa,
				'motivo_perda'       => $obLead->motivo_perda,
				'esquecido'          => $horasSemContato >= 48,
				'ultima_atualizacao' => $ultimaAtualizacao
			];
		}

		$whereCursos = $whereBase.self::montarFiltroFunil($funilAtivo);
		$cursos = self::getCursosDisponiveis($whereCursos);
		$funis  = self::listarFunisAdmin($id_admin);

		$totaisFormatados = [];
		foreach($totaisGlobaisRaw as $status => $valor){
			$totaisFormatados[$status] = NumeroHelper::moedaBr($valor);
		}

		return json_encode([
			'colunas'            => $colunas,
			'totais'             => $totaisFormatados,
			'contagens_globais'  => $contagensGlobais,
			'cursos'             => $cursos,
			'funis'              => $funis,
			'funil_ativo'        => $funilAtivo,
			'pagination'         => parent::getPagination($request, $obPagination)
		]);
	}

	/**
	 * @return array{contagens:array<string,int>,totais:array<string,float>}
	 */
	private static function agregarPorStatus(string $whereFiltro): array {
		$contagens = [];
		$totais = [];
		foreach(self::$statusPermitidos as $status){
			$contagens[$status] = 0;
			$totais[$status] = 0.0;
		}

		$results = EntityCrmLeads::getLeads(
			$whereFiltro,
			null,
			null,
			'status, COUNT(*) as qtd, SUM(COALESCE(valor_estimado,0)) as soma',
			null,
			'status'
		);

		while ($row = $results->fetchObject()) {
			$status = in_array($row->status ?? '', self::$statusPermitidos) ? $row->status : 'novo';
			$contagens[$status] = (int)($row->qtd ?? 0);
			$totais[$status] = (float)($row->soma ?? 0);
		}

		return ['contagens' => $contagens, 'totais' => $totais];
	}

	public static function getFunis($request){

		$dadosUser = parent::getIdAdmin();
		$id_admin  = (int)$dadosUser['usuario']['id_admin'];

		self::garantirFunilPadrao($id_admin);

		return json_encode([
			'funis' => self::listarFunisAdmin($id_admin, true)
		]);
	}

	public static function cadastrarFunil($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$nome = trim($postVars['nome'] ?? '');

		if($nome === ''){
			$resposta['erro'] = 'Informe o nome do funil.';
			return json_encode($resposta);
		}

		if(strlen($nome) > 100){
			$resposta['erro'] = 'O nome do funil deve ter no máximo 100 caracteres.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = (int)$dadosUser['usuario']['id_admin'];

		$existe = EntityCrmFunis::getFunis(
			'id_admin = '.$id_admin.' AND nome = "'.addslashes($nome).'"',
			null,
			1
		)->fetchObject(EntityCrmFunis::class);

		if($existe instanceof EntityCrmFunis){
			$resposta['erro'] = 'Já existe um funil com este nome.';
			return json_encode($resposta);
		}

		$obFunil = new EntityCrmFunis;
		$obFunil->id_admin = $id_admin;
		$obFunil->nome     = $nome;
		$obFunil->cadastrar();

		$resposta['sucesso'] = true;
		$resposta['funil']   = [
			'id'   => (int)$obFunil->id,
			'nome' => $obFunil->nome
		];
		$resposta['funis'] = self::listarFunisAdmin($id_admin, true);

		return json_encode($resposta);
	}

	public static function excluirFunil($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Funil inválido.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = (int)$dadosUser['usuario']['id_admin'];

		$obFunil = EntityCrmFunis::getFunis(
			'id = '.$id.' AND id_admin = '.$id_admin
		)->fetchObject(EntityCrmFunis::class);

		if(!$obFunil instanceof EntityCrmFunis){
			$resposta['erro'] = 'Funil não encontrado.';
			return json_encode($resposta);
		}

		$qtdLeads = (int)EntityCrmLeads::getLeads(
			'funil_id = '.$id.' AND id_admin = '.$id_admin,
			null,
			null,
			'COUNT(*) as qtd'
		)->fetchObject()->qtd;

		if($qtdLeads > 0){
			$resposta['erro'] = 'Este funil possui '.$qtdLeads.' lead(s). Mova ou exclua os leads antes de remover o funil.';
			return json_encode($resposta);
		}

		$obFunil->excluir();

		$resposta['sucesso'] = true;
		$resposta['funis']   = self::listarFunisAdmin($id_admin, true);

		return json_encode($resposta);
	}

	public static function cadastrar($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$nome = trim($postVars['nome'] ?? '');
		$whatsapp = preg_replace('/\D/','',$postVars['whatsapp'] ?? '');
		$curso = trim($postVars['curso_interesse'] ?? '');
		$valorEstimado = self::parseValor($postVars['valor_estimado'] ?? '');
		$dadosCadastrais = self::extrairDadosCadastrais($postVars);

		if($nome == ''){
			$resposta['erro'] = 'Informe o nome do lead.';
			return json_encode($resposta);
		}

		if(strlen($whatsapp) < 10){
			$resposta['erro'] = 'Informe um WhatsApp válido (apenas números).';
			return json_encode($resposta);
		}

		if(!empty($dadosCadastrais['erro'])){
			$resposta['erro'] = $dadosCadastrais['erro'];
			return json_encode($resposta);
		}

		$visibilidade = $postVars['visibilidade'] ?? 'publico';
		if(!in_array($visibilidade, self::$visibilidadesPermitidas)){
			$resposta['erro'] = 'Visibilidade do lead inválida.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$funilId = (int)($postVars['funil_id'] ?? 0);
		if(!self::validarFunil($funilId, (int)$id_admin)){
			$resposta['erro'] = 'Selecione um funil válido.';
			return json_encode($resposta);
		}

		$nomeFunil = self::getNomeFunil($funilId);

		$msgNovo = 'Lead cadastrado no CRM com status "'.self::$labelsStatus['novo'].'".';
		if ($valorEstimado > 0) {
			$msgNovo .= ' Valor estimado: R$ '.NumeroHelper::moedaBr($valorEstimado).'.';
		}
		if (!empty($dadosCadastrais['origem'])) {
			$msgNovo .= ' Origem: '.$dadosCadastrais['origem'].'.';
		}
		$msgNovo .= ' Visibilidade: '.($visibilidade === 'privado' ? 'Privado' : 'Público').'.';
		if ($nomeFunil !== '') {
			$msgNovo .= ' Funil: '.$nomeFunil.'.';
		}

		$dup = CrmPessoaHelper::buscarLeadDuplicado((int)$id_admin, $whatsapp, $dadosCadastrais['email'] ?? '');

		$resLead = CrmPessoaHelper::criarOuAtualizarLead((int)$id_admin, [
			'nome' => $nome,
			'whatsapp' => $whatsapp,
			'email' => $dadosCadastrais['email'],
			'curso_interesse' => $curso,
			'valor_estimado' => $valorEstimado > 0 ? $valorEstimado : null,
			'origem' => $dadosCadastrais['origem'],
			'bairro' => $dadosCadastrais['bairro'],
			'cidade' => $dadosCadastrais['cidade'],
			'funil_id' => $funilId,
			'visibilidade' => $visibilidade,
			'historico_obs' => $dup instanceof EntityCrmLeads
				? 'Lead atualizado (já existia com mesmo WhatsApp/e-mail).'
				: $msgNovo,
		], (int)$id_usuario);

		$obLead = $resLead['lead'];
		if (!($obLead instanceof EntityCrmLeads) || (int)$obLead->id <= 0) {
			$resposta['erro'] = 'Não foi possível salvar o lead.';
			return json_encode($resposta);
		}
		if (!empty($dadosCadastrais['idade']) || !empty($dadosCadastrais['responsavel_nome'])) {
			$obLead->idade = $dadosCadastrais['idade'];
			$obLead->responsavel_nome = $dadosCadastrais['responsavel_nome'];
			$obLead->atualizarDados();
		}

		if (!empty($resLead['criado'])) {
			self::dispararMensagemWhatsApp($obLead, null, 'novo');
		}

		$resposta['sucesso'] = true;
		$resposta['atualizado'] = !empty($resLead['atualizado']);
		return json_encode($resposta);
	}

	public static function converterEmAluno($request){
		$postVars = $request->getPostVars();
		$id = (int)($postVars['id'] ?? 0);
		if ($id <= 0) {
			return json_encode(['erro' => 'Lead inválido.']);
		}
		$dadosUser = parent::getIdAdmin();
		$id_admin = (int)$dadosUser['usuario']['id_admin'];
		$id_usuario = (int)$dadosUser['usuario']['id'];

		$obLead = EntityCrmLeads::getLeads(
			'id = '.$id.' AND id_admin = '.(int)$id_admin
		)->fetchObject(EntityCrmLeads::class);
		if (!$obLead instanceof EntityCrmLeads) {
			return json_encode(['erro' => 'Lead não encontrado.']);
		}
		if (!self::podeAcessarLead($obLead, $dadosUser)) {
			return json_encode(['erro' => 'Sem permissão para este lead.']);
		}

		$conv = CrmPessoaHelper::converterLeadEmCliente($id_admin, $id, $id_usuario);
		if (empty($conv['ok'])) {
			return json_encode(['erro' => $conv['message'] ?? 'Falha ao converter.']);
		}

		return json_encode([
			'sucesso' => true,
			'message' => $conv['message'] ?? 'Aluno pronto.',
			'id_usuario' => (int)($conv['id_usuario'] ?? 0),
			'url_matricula' => rtrim((string)URL, '/').'/painel/matriculas?aluno='.(int)($conv['id_usuario'] ?? 0).'&lead='.$id,
		]);
	}

	/** Disparo WA automático (uso por CrmPessoaHelper após matrícula). */
	public static function dispararAutomacaoStatusPublico($lead, $statusAnterior, $statusNovo, int $usuarioId = 0): void {
		self::dispararMensagemWhatsApp($lead, $statusAnterior, $statusNovo, $usuarioId);
	}

	public static function importar($request){

		$postVars = $request->getPostVars();
		$fileVars = $request->getFileVars();
		$resposta = [];

		$visibilidade = $postVars['visibilidade'] ?? 'publico';

		if(!in_array($visibilidade, self::$visibilidadesPermitidas)){
			$resposta['erro'] = 'Visibilidade inválida.';
			return json_encode($resposta);
		}

		$dadosUser  = parent::getIdAdmin();
		$id_admin   = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$funilId = (int)($postVars['funil_id'] ?? 0);
		if(!self::validarFunil($funilId, (int)$id_admin)){
			$resposta['erro'] = 'Selecione um funil válido para a importação.';
			return json_encode($resposta);
		}

		$nomeFunil = self::getNomeFunil($funilId);

		$arquivo = $fileVars['planilha'] ?? null;

		if(!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
			$resposta['erro'] = 'Selecione um arquivo CSV ou XLSX válido.';
			return json_encode($resposta);
		}

		$nomeArquivo = $arquivo['name'] ?? '';
		$extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

		if(!in_array($extensao, ['csv', 'xlsx'])){
			$resposta['erro'] = 'Formato não suportado. Envie arquivos .csv ou .xlsx.';
			return json_encode($resposta);
		}

		$tamanhoMaximo = 5 * 1024 * 1024;

		if(($arquivo['size'] ?? 0) > $tamanhoMaximo){
			$resposta['erro'] = 'Arquivo muito grande. O limite é 5MB.';
			return json_encode($resposta);
		}

		try{
			$linhas = PlanilhaHelper::lerArquivo($arquivo['tmp_name'], $extensao);
		}catch(\Throwable $e){
			$resposta['erro'] = 'Erro ao ler a planilha: '.$e->getMessage();
			return json_encode($resposta);
		}

		if(count($linhas) === 0){
			$resposta['erro'] = 'A planilha não contém linhas para importar.';
			return json_encode($resposta);
		}

		if(PlanilhaHelper::pareceCabecalho($linhas[0])){
			array_shift($linhas);
		}

		if(count($linhas) === 0){
			$resposta['erro'] = 'A planilha possui apenas o cabeçalho, sem dados.';
			return json_encode($resposta);
		}

		$importados = 0;
		$atualizados = 0;
		$ignorados   = 0;
		$erros       = [];

		foreach($linhas as $indice => $linha){

			$numeroLinha = $indice + 2;
			$dadosLinha  = self::extrairDadosPlanilha($linha);

			if(!empty($dadosLinha['erro'])){
				$ignorados++;
				$erros[] = 'Linha '.$numeroLinha.': '.$dadosLinha['erro'];
				continue;
			}

			$resLead = CrmPessoaHelper::criarOuAtualizarLead((int)$id_admin, [
				'nome' => $dadosLinha['nome'],
				'whatsapp' => $dadosLinha['whatsapp'],
				'email' => $dadosLinha['email'],
				'curso_interesse' => $dadosLinha['curso_interesse'],
				'bairro' => $dadosLinha['bairro'],
				'cidade' => $dadosLinha['cidade'],
				'funil_id' => $funilId,
				'visibilidade' => $visibilidade,
				'origem' => 'Importação planilha',
				'historico_obs' => 'Lead importado via planilha no funil "'.$nomeFunil.'".',
			], (int)$id_usuario);

			if (empty($resLead['lead']) || (int)$resLead['lead']->id <= 0) {
				$ignorados++;
				continue;
			}

			if (!empty($resLead['atualizado'])) {
				$atualizados++;
			} else {
				$importados++;
			}
		}

		if($importados === 0 && $atualizados === 0){
			$resposta['erro'] = 'Nenhum lead foi importado. Verifique o formato das linhas.';
			if(count($erros) > 0){
				$resposta['detalhes'] = array_slice($erros, 0, 5);
			}
			return json_encode($resposta);
		}

		$resposta['sucesso']    = true;
		$resposta['importados'] = $importados;
		$resposta['atualizados'] = $atualizados;
		$resposta['ignorados']  = $ignorados;

		if(count($erros) > 0){
			$resposta['avisos'] = array_slice($erros, 0, 10);
		}

		return json_encode($resposta);
	}

	public static function atualizarStatus($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id          = isset($postVars['id']) ? (int)$postVars['id'] : 0;
		$status      = $postVars['status'] ?? '';
		$motivoPerda = trim($postVars['motivo_perda'] ?? '');

		if($id <= 0){
			$resposta['erro'] = 'Lead inválido.';
			return json_encode($resposta);
		}

		if(!in_array($status, self::$statusPermitidos)){
			$resposta['erro'] = 'Status inválido.';
			return json_encode($resposta);
		}

		if($status === 'perdido' && !in_array($motivoPerda, self::$motivosPerda)){
			$resposta['erro'] = 'Informe o motivo da perda.';
			$resposta['requer_motivo'] = true;
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$obLead = EntityCrmLeads::getLeads(
			'id = '.$id.' AND id_admin = '.(int)$id_admin
		)->fetchObject(EntityCrmLeads::class);

		if(!$obLead instanceof EntityCrmLeads){
			$resposta['erro'] = 'Lead não encontrado.';
			return json_encode($resposta);
		}

		if(!self::podeAcessarLead($obLead, $dadosUser)){
			$resposta['erro'] = 'Você não tem permissão para alterar este lead.';
			return json_encode($resposta);
		}

		$statusAnterior = $obLead->status;

		if($statusAnterior === $status){
			$resposta['sucesso'] = true;
			return json_encode($resposta);
		}

		if($status === 'em_atendimento'){
			$obLead->usuario_id = $id_usuario;
		}

		$obLead->status = $status;
		$obLead->motivo_perda = ($status === 'perdido') ? $motivoPerda : null;
		$obLead->atualizarStatus();

		$labelAnterior = self::$labelsStatus[$statusAnterior] ?? $statusAnterior;
		$labelNovo     = self::$labelsStatus[$status] ?? $status;

		$msgHistorico = 'Status alterado de "'.$labelAnterior.'" para "'.$labelNovo.'".';
		if($status === 'perdido'){
			$msgHistorico .= ' Motivo da perda: '.$motivoPerda.'.';
		}
		if($status === 'em_atendimento'){
			$msgHistorico .= ' Lead assumido para atendimento exclusivo.';
		}

		self::registrarHistorico($obLead->id, $id_usuario, 'status_alterado', $msgHistorico);

		self::dispararMensagemWhatsApp($obLead, $statusAnterior, $status, (int)$id_usuario);

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function getDetalhes($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Lead inválido.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];

		$obLead = EntityCrmLeads::getLeads(
			'id = '.$id.' AND id_admin = '.(int)$id_admin
		)->fetchObject(EntityCrmLeads::class);

		if(!$obLead instanceof EntityCrmLeads){
			$resposta['erro'] = 'Lead não encontrado.';
			return json_encode($resposta);
		}

		if(!self::podeAcessarLead($obLead, $dadosUser)){
			$resposta['erro'] = 'Você não tem permissão para visualizar este lead.';
			return json_encode($resposta);
		}

		$resposta['lead'] = self::formatarLeadDetalhes($obLead);
		$resposta['funis'] = self::listarFunisAdmin($id_admin);

		$resposta['whatsapp_link'] = self::montarLinkWhatsApp($obLead->whatsapp);
		$resposta['timeline']      = self::montarTimeline($obLead->id);

		return json_encode($resposta);
	}

	public static function salvarComentario($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id         = isset($postVars['id']) ? (int)$postVars['id'] : 0;
		$observacao = trim($postVars['observacao'] ?? '');

		if($id <= 0){
			$resposta['erro'] = 'Lead inválido.';
			return json_encode($resposta);
		}

		if($observacao == ''){
			$resposta['erro'] = 'Digite uma observação antes de salvar.';
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$obLead = EntityCrmLeads::getLeads(
			'id = '.$id.' AND id_admin = '.(int)$id_admin
		)->fetchObject(EntityCrmLeads::class);

		if(!$obLead instanceof EntityCrmLeads){
			$resposta['erro'] = 'Lead não encontrado.';
			return json_encode($resposta);
		}

		if(!self::podeAcessarLead($obLead, $dadosUser)){
			$resposta['erro'] = 'Você não tem permissão para comentar neste lead.';
			return json_encode($resposta);
		}

		self::registrarHistorico($obLead->id, $id_usuario, 'comentario', $observacao);

		$resposta['sucesso']  = true;
		$resposta['timeline'] = self::montarTimeline($obLead->id);

		return json_encode($resposta);
	}

	public static function atualizarDados($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Lead inválido.';
			return json_encode($resposta);
		}

		$nome = trim($postVars['nome'] ?? '');
		$whatsapp = preg_replace('/\D/','',$postVars['whatsapp'] ?? '');
		$curso = trim($postVars['curso_interesse'] ?? '');
		$valorEstimado = self::parseValor($postVars['valor_estimado'] ?? '');
		$dadosCadastrais = self::extrairDadosCadastrais($postVars);

		if($nome == ''){
			$resposta['erro'] = 'Informe o nome do lead.';
			return json_encode($resposta);
		}

		if(strlen($whatsapp) < 10){
			$resposta['erro'] = 'Informe um WhatsApp válido (apenas números).';
			return json_encode($resposta);
		}

		if(!empty($dadosCadastrais['erro'])){
			$resposta['erro'] = $dadosCadastrais['erro'];
			return json_encode($resposta);
		}

		$dadosUser = parent::getIdAdmin();
		$id_admin  = $dadosUser['usuario']['id_admin'];
		$id_usuario = $dadosUser['usuario']['id'];

		$obLead = EntityCrmLeads::getLeads(
			'id = '.$id.' AND id_admin = '.(int)$id_admin
		)->fetchObject(EntityCrmLeads::class);

		if(!$obLead instanceof EntityCrmLeads){
			$resposta['erro'] = 'Lead não encontrado.';
			return json_encode($resposta);
		}

		if(!self::podeAcessarLead($obLead, $dadosUser)){
			$resposta['erro'] = 'Você não tem permissão para editar este lead.';
			return json_encode($resposta);
		}

		$funilId = (int)($postVars['funil_id'] ?? 0);
		if(!self::validarFunil($funilId, (int)$id_admin)){
			$resposta['erro'] = 'Selecione um funil válido.';
			return json_encode($resposta);
		}

		$funilAnterior = (int)($obLead->funil_id ?? 0);

		$obLead->nome             = $nome;
		$obLead->whatsapp         = $whatsapp;
		$obLead->curso_interesse  = $curso;
		$obLead->valor_estimado   = $valorEstimado > 0 ? $valorEstimado : null;
		$obLead->origem           = $dadosCadastrais['origem'];
		$obLead->email            = $dadosCadastrais['email'];
		$obLead->bairro           = $dadosCadastrais['bairro'];
		$obLead->cidade           = $dadosCadastrais['cidade'];
		$obLead->idade            = $dadosCadastrais['idade'];
		$obLead->responsavel_nome = $dadosCadastrais['responsavel_nome'];
		$obLead->funil_id         = $funilId;
		$obLead->atualizarDados();

		$msgHistorico = 'Dados cadastrais do lead foram atualizados.';
		if($funilAnterior !== $funilId){
			$nomeAnterior = self::getNomeFunil($funilAnterior) ?: 'Sem funil';
			$nomeNovo     = self::getNomeFunil($funilId);
			$msgHistorico .= ' Funil alterado de "'.$nomeAnterior.'" para "'.$nomeNovo.'".';
		}

		self::registrarHistorico(
			$obLead->id,
			$id_usuario,
			'dados_atualizados',
			$msgHistorico
		);

		$resposta['sucesso'] = true;
		$resposta['lead']    = self::formatarLeadDetalhes($obLead);
		$resposta['whatsapp_link'] = self::montarLinkWhatsApp($obLead->whatsapp);
		$resposta['timeline'] = self::montarTimeline($obLead->id);

		return json_encode($resposta);
	}

	private static function extrairDadosCadastrais($postVars){

		$origem = trim($postVars['origem'] ?? '');
		$email  = EmailValidator::normalizar($postVars['email'] ?? '');
		$bairro = trim($postVars['bairro'] ?? '');
		$cidade = trim($postVars['cidade'] ?? '');
		$idade  = trim($postVars['idade'] ?? '');
		$responsavel = trim($postVars['responsavel_nome'] ?? '');

		if($origem !== '' && !in_array($origem, self::$origensPermitidas)){
			return ['erro' => 'Origem do lead inválida.'];
		}

		$erroEmail = EmailValidator::mensagemErro($email, false);
		if($erroEmail !== null){
			return ['erro' => $erroEmail];
		}

		$idadeInt = null;
		if($idade !== ''){
			$idadeInt = (int)$idade;
			if($idadeInt < 1 || $idadeInt > 120){
				return ['erro' => 'Informe uma idade válida.'];
			}
		}

		return [
			'origem'           => $origem !== '' ? $origem : null,
			'email'            => $email !== '' ? $email : null,
			'bairro'           => $bairro !== '' ? $bairro : null,
			'cidade'           => $cidade !== '' ? $cidade : null,
			'idade'            => $idadeInt,
			'responsavel_nome' => $responsavel !== '' ? $responsavel : null
		];
	}

	private static function formatarLeadDetalhes($obLead){

		$valorEstimado = (float)($obLead->valor_estimado ?? 0);
		$idUsuario = (EntityCrmLeads::colunaIdUsuarioExiste() && !empty($obLead->id_usuario))
			? (int)$obLead->id_usuario : 0;
		$temAluno = false;
		if ($idUsuario > 0) {
			$u = EntityUser::getUserById($idUsuario);
			$temAluno = $u instanceof EntityUser && (string)($u->nivel ?? '') === 'Cliente';
		}

		return [
			'id'               => $obLead->id,
			'nome'             => $obLead->nome,
			'whatsapp'         => $obLead->whatsapp,
			'curso_interesse'  => $obLead->curso_interesse ?? '',
			'origem'           => $obLead->origem ?? '',
			'email'            => $obLead->email ?? '',
			'bairro'           => $obLead->bairro ?? '',
			'cidade'           => $obLead->cidade ?? '',
			'idade'            => $obLead->idade ?? '',
			'responsavel_nome' => $obLead->responsavel_nome ?? '',
			'valor_estimado'   => $valorEstimado > 0 ? NumeroHelper::moedaBr($valorEstimado) : '',
			'valor_estimado_raw' => $valorEstimado > 0 ? $valorEstimado : '',
			'motivo_perda'     => $obLead->motivo_perda ?: '-',
			'status'           => $obLead->status,
			'status_label'     => self::$labelsStatus[$obLead->status] ?? $obLead->status,
			'visibilidade'     => $obLead->visibilidade ?? 'publico',
			'visibilidade_label' => ($obLead->visibilidade ?? 'publico') === 'privado' ? 'Privado' : 'Público',
			'funil_id'         => (int)($obLead->funil_id ?? 0),
			'funil_nome'       => self::getNomeFunil((int)($obLead->funil_id ?? 0)),
			'data_cadastro'    => DateTimeHelper::databr($obLead->data_cadastro),
			'status_wa'        => $obLead->status_wa,
			'id_usuario'       => $idUsuario,
			'tem_aluno'        => $temAluno,
			'pode_matricular'  => $obLead->status !== 'matriculado',
		];
	}

	private static function parseValor($valorBruto){
		if($valorBruto === '' || $valorBruto === null){
			return 0;
		}
		return (float) NumeroHelper::removerFormatacaoNumero($valorBruto);
	}

	private static function isAdministrador($dadosUser){
		$nivel = $dadosUser['usuario']['nivel'] ?? '';
		return in_array($nivel, self::$niveisAdministrador);
	}

	private static function montarFiltrosBusca($postVars){

		$filtros = '';

		$nome = trim($postVars['filtro_nome'] ?? '');
		if($nome !== ''){
			$filtros .= ' AND nome LIKE "%'.addslashes($nome).'%"';
		}

		$curso = trim($postVars['filtro_curso'] ?? '');
		if($curso !== ''){
			$filtros .= ' AND LOWER(curso_interesse) = LOWER("'.addslashes($curso).'")';
		}

		return $filtros;
	}

	private static function montarFiltroFunil($funilId){
		return ' AND funil_id = '.(int)$funilId;
	}

	private static function resolverFunilFiltro($postVars, $id_admin){

		$funilId = (int)($postVars['filtro_funil'] ?? 0);

		if($funilId > 0 && self::validarFunil($funilId, $id_admin)){
			return $funilId;
		}

		return self::garantirFunilPadrao($id_admin);
	}

	private static function garantirFunilPadrao($id_admin){

		$obFunil = EntityCrmFunis::getFunis(
			'id_admin = '.(int)$id_admin.' AND nome = "Geral"',
			null,
			1
		)->fetchObject(EntityCrmFunis::class);

		if($obFunil instanceof EntityCrmFunis){
			return (int)$obFunil->id;
		}

		$obFunil = EntityCrmFunis::getFunis(
			'id_admin = '.(int)$id_admin.' AND ativo = 1',
			'nome ASC',
			1
		)->fetchObject(EntityCrmFunis::class);

		if($obFunil instanceof EntityCrmFunis){
			return (int)$obFunil->id;
		}

		$obNovoFunil = new EntityCrmFunis;
		$obNovoFunil->id_admin = (int)$id_admin;
		$obNovoFunil->nome     = 'Geral';
		$obNovoFunil->cadastrar();

		return (int)$obNovoFunil->id;
	}

	private static function validarFunil($funilId, $id_admin){

		if($funilId <= 0){
			return false;
		}

		$obFunil = EntityCrmFunis::getFunis(
			'id = '.(int)$funilId.' AND id_admin = '.(int)$id_admin.' AND ativo = 1'
		)->fetchObject(EntityCrmFunis::class);

		return $obFunil instanceof EntityCrmFunis;
	}

	private static function getNomeFunil($funilId){

		if($funilId <= 0){
			return '';
		}

		$obFunil = EntityCrmFunis::getFunilById($funilId);

		return ($obFunil instanceof EntityCrmFunis) ? $obFunil->nome : '';
	}

	private static function listarFunisAdmin($id_admin, $comContagem = false){

		self::garantirFunilPadrao($id_admin);

		$funis = [];

		$results = EntityCrmFunis::getFunis(
			'id_admin = '.(int)$id_admin.' AND ativo = 1',
			'nome ASC'
		);

		while ($row = $results->fetch(\PDO::FETCH_ASSOC)) {
			$item = [
				'id'   => (int)$row['id'],
				'nome' => $row['nome']
			];

			if($comContagem){
				$countRow = EntityCrmLeads::getLeads(
					'funil_id = '.(int)$row['id'].' AND id_admin = '.(int)$id_admin,
					null,
					null,
					'COUNT(*) as qtd'
				)->fetch(\PDO::FETCH_ASSOC);

				$item['qtd_leads'] = (int)($countRow['qtd'] ?? 0);
			}

			$funis[] = $item;
		}

		return $funis;
	}

	private static function getCursosDisponiveis($where){

		$cursos = [];

		$results = EntityCrmLeads::getLeads(
			$where.' AND curso_interesse IS NOT NULL AND curso_interesse != ""',
			'curso_interesse ASC',
			null,
			'DISTINCT curso_interesse'
		);

		while ($obLead = $results->fetchObject(EntityCrmLeads::class)) {
			if(!empty($obLead->curso_interesse)){
				$cursos[] = $obLead->curso_interesse;
			}
		}

		return $cursos;
	}

	private static function montarWhereListagem($dadosUser){

		$id_admin   = (int)$dadosUser['usuario']['id_admin'];
		$id_usuario = (int)$dadosUser['usuario']['id'];

		$where = 'id_admin = '.$id_admin;

		if(!self::isAdministrador($dadosUser)){
			$where .= ' AND (
				usuario_id = '.$id_usuario.'
				OR (
					visibilidade = "publico"
					AND NOT (
						status = "em_atendimento"
						AND usuario_id IS NOT NULL
						AND usuario_id != '.$id_usuario.'
					)
				)
			)';
		}

		return $where;
	}

	private static function podeAcessarLead($obLead, $dadosUser){

		if(self::isAdministrador($dadosUser)){
			return true;
		}

		$id_usuario   = (int)$dadosUser['usuario']['id'];
		$usuarioLead  = (int)($obLead->usuario_id ?? 0);
		$visibilidade = $obLead->visibilidade ?? 'publico';

		if($usuarioLead === $id_usuario){
			return true;
		}

		if($visibilidade !== 'publico'){
			return false;
		}

		if(
			$obLead->status === 'em_atendimento'
			&& $usuarioLead > 0
			&& $usuarioLead !== $id_usuario
		){
			return false;
		}

		return true;
	}

	private static function extrairDadosPlanilha($linha){

		$nome           = trim($linha[0] ?? '');
		$whatsappBruto  = trim($linha[1] ?? '');
		$email          = trim($linha[2] ?? '');
		$cursoInteresse = trim($linha[3] ?? '');
		$bairro         = trim($linha[4] ?? '');
		$cidade         = trim($linha[5] ?? '');

		$whatsapp = preg_replace('/\D/', '', $whatsappBruto);

		if($nome === ''){
			return ['erro' => 'Nome não informado.'];
		}

		if(strlen($whatsapp) < 10){
			return ['erro' => 'WhatsApp inválido (mínimo 10 dígitos com DDD).'];
		}

		$email = self::normalizarEmailPlanilha($email);

		// Planilha sem coluna de e-mail: a 3ª coluna pode ser o curso
		if($email !== '' && strpos($email, '@') === false){
			if($cursoInteresse === ''){
				$cursoInteresse = $email;
			}
			$email = '';
		}

		if($email !== '' && !EmailValidator::isValido($email)){
			return ['erro' => EmailValidator::mensagemErro($email, false) ?: 'E-mail inválido.'];
		}

		return [
			'nome'            => $nome,
			'whatsapp'        => $whatsapp,
			'email'           => $email !== '' ? $email : null,
			'curso_interesse' => $cursoInteresse !== '' ? $cursoInteresse : null,
			'bairro'          => $bairro !== '' ? $bairro : null,
			'cidade'          => $cidade !== '' ? $cidade : null
		];
	}

	private static function normalizarEmailPlanilha($email){

		$email = trim($email);

		if($email === ''){
			return '';
		}

		$vazio = ['-', '--', 'n/a', 'na', 'sem email', 'sem e-mail', 's/email', 'null', 'nenhum'];

		if(in_array(strtolower($email), $vazio)){
			return '';
		}

		return $email;
	}

	private static function getUltimaAtualizacao($leadId, $dataCadastro){

		$obHist = EntityCrmHistorico::getHistorico(
			'lead_id = '.(int)$leadId,
			'data_registro DESC',
			1
		)->fetchObject(EntityCrmHistorico::class);

		if($obHist instanceof EntityCrmHistorico){
			return $obHist->data_registro;
		}

		return $dataCadastro;
	}

	private static function horasDesde($dataHora){
		$timestamp = strtotime($dataHora);
		if(!$timestamp){
			return 0;
		}
		return (time() - $timestamp) / 3600;
	}

	private static function registrarHistorico($leadId, $usuarioId, $acao, $observacao){

		$obHistorico = new EntityCrmHistorico;
		$obHistorico->lead_id    = $leadId;
		$obHistorico->usuario_id = $usuarioId;
		$obHistorico->acao       = $acao;
		$obHistorico->observacao = $observacao;
		$obHistorico->cadastrar();
	}

	private static function montarLinkWhatsApp($whatsapp){

		$numero = preg_replace('/\D/','',$whatsapp);

		if(strlen($numero) <= 11){
			$numero = '55'.$numero;
		}

		return 'https://wa.me/'.$numero;
	}

	private static function montarTimeline($leadId){

		$results = EntityCrmHistorico::getHistorico(
			'lead_id = '.(int)$leadId,
			'data_registro DESC'
		);

		$itens = '';

		while ($obHist = $results->fetchObject(EntityCrmHistorico::class)) {

			$obUser = EntityUser::getUserById($obHist->usuario_id);
			$nomeUsuario = ($obUser instanceof EntityUser) ? $obUser->nome : 'Sistema';

			$icone  = 'fa-circle-info text-primary';
			$titulo = 'Registro';

			if($obHist->acao == 'status_alterado'){
				$icone  = 'fa-arrows-left-right text-warning';
				$titulo = 'Mudança de status';
			} elseif($obHist->acao == 'comentario'){
				$icone  = 'fa-comment-dots text-success';
				$titulo = 'Comentário';
			} elseif($obHist->acao == 'lead_cadastrado'){
				$icone  = 'fa-user-plus text-primary';
				$titulo = 'Lead cadastrado';
			} elseif($obHist->acao == 'lead_importado'){
				$icone  = 'fa-file-import text-info';
				$titulo = 'Importação de planilha';
			} elseif($obHist->acao == 'dados_atualizados'){
				$icone  = 'fa-pen text-info';
				$titulo = 'Dados atualizados';
			} elseif($obHist->acao == 'whatsapp_automatico'){
				$icone  = 'fa-brands fa-whatsapp text-success';
				$titulo = 'WhatsApp automático';
			}

			$dataFormatada = DateTimeHelper::databr($obHist->data_registro);
			$horaFormatada = DateTimeHelper::extrairHorario($obHist->data_registro);

			$itens .= '
			<li class="timeline-item">
				<div class="timeline-icon">
					<i class="fas '.$icone.'"></i>
				</div>
				<div class="timeline-content">
					<div class="d-flex justify-content-between align-items-start">
						<strong>'.$titulo.'</strong>
						<small class="text-muted">'.$dataFormatada.' '.$horaFormatada.'</small>
					</div>
					<p class="mb-1">'.nl2br(htmlspecialchars($obHist->observacao)).'</p>
					<small class="text-muted">por '.htmlspecialchars($nomeUsuario).'</small>
				</div>
			</li>';
		}

		if($itens == ''){
			return '<p class="text-muted small mb-0">Nenhum histórico registrado ainda.</p>';
		}

		return '<ul class="timeline-list">'.$itens.'</ul>';
	}

	private static function dispararMensagemWhatsApp($lead, $statusAnterior, $statusNovo, int $usuarioId = 0){

		if($statusAnterior === $statusNovo){
			return;
		}

		$idAdmin = (int)($lead->id_admin ?? 0);
		if($idAdmin <= 0){
			return;
		}

		$mensagem = CrmAutomacaoTemplateHelper::mensagemParaLead($idAdmin, $statusNovo, $lead);
		if($mensagem === null || $mensagem === ''){
			return;
		}

		$telefone = preg_replace('/\D/', '', (string)($lead->whatsapp ?? ''));
		if($telefone === ''){
			return;
		}

		$labelStatus = self::$labelsStatus[$statusNovo] ?? $statusNovo;
		$modsEscola = ModuleGateHelper::getModulosEscola($idAdmin);
		if (!in_array('WhatsApp', $modsEscola, true)) {
			self::marcarStatusWaLead((int)$lead->id, 'pulado');
			self::registrarHistoricoWaOpcional(
				(int)$lead->id,
				$usuarioId,
				'WhatsApp automático não enviado ao mudar para "'.$labelStatus.'": escola sem módulo WhatsApp (Evolution).'
			);
			return;
		}

		$statusWa = WhatsappEscolaService::status($idAdmin);
		if (empty($statusWa['conectado'])) {
			self::marcarStatusWaLead((int)$lead->id, 'pulado');
			$motivo = !empty($statusWa['erro'])
				? (string)$statusWa['erro']
				: 'WhatsApp/Evolution não conectado.';
			self::registrarHistoricoWaOpcional(
				(int)$lead->id,
				$usuarioId,
				'WhatsApp automático não enviado ao mudar para "'.$labelStatus.'": '.$motivo
			);
			return;
		}

		$envio = WhatsappEscolaService::enviarTexto($idAdmin, $telefone, $mensagem);
		if (!empty($envio['ok'])) {
			self::marcarStatusWaLead((int)$lead->id, 'enviado');
			self::registrarHistoricoWaOpcional(
				(int)$lead->id,
				$usuarioId,
				'Mensagem automática enviada ao mudar para "'.$labelStatus.'".'
			);
			return;
		}

		self::marcarStatusWaLead((int)$lead->id, 'erro');
		$erroMsg = trim((string)($envio['message'] ?? 'Falha ao enviar'));
		self::registrarHistoricoWaOpcional(
			(int)$lead->id,
			$usuarioId,
			'Falha no WhatsApp automático ("'.$labelStatus.'"): '.$erroMsg
		);
	}

	private static function marcarStatusWaLead(int $leadId, string $statusWa): void {
		if ($leadId <= 0) {
			return;
		}
		try {
			(new Database('crm_leads'))->update('id = '.$leadId, ['status_wa' => $statusWa]);
		} catch (\Throwable $e) {
			// coluna/status legado — não interrompe o fluxo do CRM
		}
	}

	private static function registrarHistoricoWaOpcional(int $leadId, int $usuarioId, string $observacao): void {
		if ($leadId <= 0) {
			return;
		}
		$uid = $usuarioId > 0 ? $usuarioId : (int)(parent::getIdAdmin()['usuario']['id'] ?? 0);
		if ($uid <= 0) {
			return;
		}
		self::registrarHistorico($leadId, $uid, 'whatsapp_automatico', $observacao);
	}

}
