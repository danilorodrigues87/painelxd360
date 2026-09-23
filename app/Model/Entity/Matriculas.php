<?php

namespace App\Model\Entity;
use App\Model\Db\Database;
use App\Model\Entity\Caixa;
use App\Model\Entity\User as EntityUser;
use \App\Model\Entity\Responsaveis as EntityRes;
use App\Common\Helpers\MercadoPagoEscolaHelper;
use App\Model\Entity\ClientesAssinantes;

class Matriculas{

	public 
	$id,
	$id_aluno,
	$id_admin,
	$id_responsavel,
	$id_trilha,
	$carga_horaria,
	$modulos,
	$horarios,
	$dia_semana,
	$aulas_semanais,
	$valor,
	$qtd_parcelas,
	$dia_vencimento,
	$primeiro_mes,
	$primeiro_ano,
	$inicio,
	$fim,
	$matriculado_em,
	$tipo_parcelamento,
	$desconto_pontualidade = 0,
	$bolsista = 0,
	$multa_atraso_pct = 2.0,
	$juros_mora_pct_mes = 1.0,
	$multa_cancelamento_pct = 10.0,
	$carencia_dias = 7,
	$data_cancelamento = null,
	$status = 0;

	private static $colunaBolsista = null;
	private static $colunaEncargos = null;
	private static $colunaDataCancelamento = null;

	public static function temColunaBolsista(): bool {
		if (self::$colunaBolsista !== null) {
			return self::$colunaBolsista;
		}
		try {
			$row = (new Database())->execute("SHOW COLUMNS FROM `matriculas` LIKE 'bolsista'")->fetch(\PDO::FETCH_ASSOC);
			self::$colunaBolsista = !empty($row);
		} catch (\Throwable $e) {
			self::$colunaBolsista = false;
		}
		return self::$colunaBolsista;
	}

	public static function temColunaEncargos(): bool {
		if (self::$colunaEncargos !== null) {
			return self::$colunaEncargos;
		}
		try {
			$row = (new Database())->execute("SHOW COLUMNS FROM `matriculas` LIKE 'multa_atraso_pct'")->fetch(\PDO::FETCH_ASSOC);
			self::$colunaEncargos = !empty($row);
		} catch (\Throwable $e) {
			self::$colunaEncargos = false;
		}
		return self::$colunaEncargos;
	}

	public static function temColunaDataCancelamento(): bool {
		if (self::$colunaDataCancelamento !== null) {
			return self::$colunaDataCancelamento;
		}
		try {
			$row = (new Database())->execute("SHOW COLUMNS FROM `matriculas` LIKE 'data_cancelamento'")->fetch(\PDO::FETCH_ASSOC);
			self::$colunaDataCancelamento = !empty($row);
		} catch (\Throwable $e) {
			self::$colunaDataCancelamento = false;
		}
		return self::$colunaDataCancelamento;
	}

	
	//ENVIA A MENSAGEM PARA O BANCO
	public function matricular(){

		//INSERIR OSDADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('matriculas');
		$dadosInsert = [

			'id_aluno' => $this->id_aluno,
			'id_admin' => $this->id_admin,
			'id_responsavel' => $this->id_responsavel,
			'id_trilha' => $this->id_trilha,
			'carga_horaria' => $this->carga_horaria,
			'modulos' => $this->modulos,
			'horarios' => $this->horarios,
			'dia_semana' => $this->dia_semana,
			'aulas_semanais' => $this->aulas_semanais,
			'valor' => $this->valor,
			'qtd_parcelas' => $this->qtd_parcelas,
			'dia_vencimento' => $this->dia_vencimento,
			'primeiro_mes' => $this->primeiro_mes,
			'primeiro_ano' => $this->primeiro_ano,
			'inicio' => $this->inicio,
			'fim' => $this->fim,
			'matriculado_em' => $this->inicio ?: date('Y-m-d'),
			'tipo_parcelamento' => $this->tipo_parcelamento,
			'desconto_pontualidade' => $this->desconto_pontualidade,
			'status' => $this->status,

		];
		if (self::temColunaBolsista()) {
			$dadosInsert['bolsista'] = !empty($this->bolsista) ? 1 : 0;
		}
		if (self::temColunaEncargos()) {
			$dadosInsert['multa_atraso_pct'] = round(max(0, (float)($this->multa_atraso_pct ?? 2)), 2);
			$dadosInsert['juros_mora_pct_mes'] = round(max(0, (float)($this->juros_mora_pct_mes ?? 1)), 2);
			$dadosInsert['multa_cancelamento_pct'] = round(max(0, (float)($this->multa_cancelamento_pct ?? 10)), 2);
			$dadosInsert['carencia_dias'] = max(0, (int)($this->carencia_dias ?? 7));
		}
		$this->id = $obDatabase->insert($dadosInsert);

		// Bolsista: matrícula ativa sem gerar carnê/débitos
		if (!empty($this->bolsista)) {
			return true;
		}

		
		if(!$this->id_responsavel){
			$idPagador = $this->id_aluno;
			$dadosPagador = (array)EntityUser::getUserById($idPagador);
		} else {
			$idPagador = $this->id_responsavel;
			$dadosPagador = (array)EntityRes::getResById($idPagador);
		}

		$dadosAluno = (array)EntityUser::getUserById($this->id_aluno);
		

		if($this->desconto_pontualidade){
			$desconto = $this->valor*10/100;
		} else {
			$desconto = 0;
		}

		$gerarPix = ($this->tipo_parcelamento === 'Carnê com Pix')
			&& MercadoPagoEscolaHelper::escolaTemPixAtivo((int)$this->id_admin);
		$pixGateway = $gerarPix ? MercadoPagoEscolaHelper::pixDaEscola((int)$this->id_admin) : null;

		$escola = ClientesAssinantes::getEscolaById((int)$this->id_admin);
		$emailEscola = '';
		$nomeEscola = 'ESCOLA';
		if ($escola instanceof ClientesAssinantes) {
			$emailEscola = trim((string)($escola->email ?? ''));
			$nomeEscola = trim((string)($escola->nome ?? 'ESCOLA')) ?: 'ESCOLA';
		}
		$notificationUrl = MercadoPagoEscolaHelper::webhookUrl((int)$this->id_admin);

		$count = 1;
		$ano_vence = $this->primeiro_ano;
		$mes_vence = $this->primeiro_mes - 1;

		while ($count <= $this->qtd_parcelas) {

			if ($mes_vence == 12) { 
				$ano_vence = $ano_vence + 1;
				$mes_vence = 1;
			} else {
				$mes_vence++;
			}

			$vencimento = date("Y-m-d", strtotime($ano_vence.'/'.$mes_vence.'/'.$this->dia_vencimento));
			$descricao = 'Cód '.$this->id.' '.$dadosAluno['nome'].' parc '.$count.'/'.$this->qtd_parcelas;

			$pixCopiaECola = '';
			$txtId = '';

			//NOVA INSTANCIA
			$obCaixa = new Caixa;
			$obCaixa->id_admin = $this->id_admin;
			$obCaixa->descricao = $descricao;
			$obCaixa->tipo_transacao = 'Entrada';
			$obCaixa->valor = $this->valor;
			$obCaixa->vencimento = $vencimento;
			$obCaixa->referencia = 'Mtrcicula Curso';
			$obCaixa->id_ref = $this->id;
			$obCaixa->status = \App\Common\Helpers\FinanceiroAlunoHelper::STATUS_ABERTO;
			$obCaixa->tipo_pagamento = '';
			$obCaixa->valor_pago = 0;
			$obCaixa->txt_id = '';
			$obCaixa->pix_copia_cola = '';
			$obCaixa->lancarMovimentacao();

			if ($pixGateway && !empty($obCaixa->id)) {
				$emailPagador = trim((string)($dadosPagador['email'] ?? ''));
				if ($emailPagador === '') {
					$emailPagador = trim((string)($dadosAluno['email'] ?? ''));
				}
				$pix = $pixGateway->criarCobrancaPix([
					'valor'                 => $this->valor,
					'descricao'             => $descricao,
					'vencimento'            => $vencimento,
					'external_reference'    => (int)$this->id_admin.':'.(int)$obCaixa->id,
					'notification_url'      => $notificationUrl,
					'statement_descriptor'  => $nomeEscola,
					'pagador_nome'          => (string)($dadosPagador['nome'] ?? ''),
					'pagador_cpf'           => (string)($dadosPagador['cpf'] ?? ''),
					'pagador_email'         => $emailPagador,
					'email_fallback'        => $emailEscola,
					'pagador_endereco'      => (string)($dadosPagador['endereco'] ?? ''),
					'pagador_numero'        => (string)($dadosPagador['numero'] ?? ''),
					'pagador_bairro'        => (string)($dadosPagador['bairro'] ?? ''),
					'pagador_cep'           => (string)($dadosPagador['cep'] ?? ''),
				]);
				if (is_array($pix) && !empty($pix['id']) && !empty($pix['copia_cola'])) {
					$obCaixa->txt_id = $pix['id'];
					$obCaixa->pix_copia_cola = $pix['copia_cola'];
					$obCaixa->atualizarPix();
				}
			}

			$count++;
		}

		return true;
	}

	//ATUALIZA A MENSAGEM NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		$dadosUpdate = [

			'id_aluno' => $this->id_aluno,
			'id_responsavel' => $this->id_responsavel,
			'id_trilha' => $this->id_trilha,
			'carga_horaria' => $this->carga_horaria,
			'modulos' => $this->modulos,
			'horarios' => $this->horarios,
			'dia_semana' => $this->dia_semana,
			'aulas_semanais' => $this->aulas_semanais,
			'valor' => $this->valor,
			'qtd_parcelas' => $this->qtd_parcelas,
			'dia_vencimento' => $this->dia_vencimento,
			'primeiro_mes' => $this->primeiro_mes,
			'primeiro_ano' => $this->primeiro_ano,
			'inicio' => $this->inicio,
			'fim' => $this->fim,
			'tipo_parcelamento' => $this->tipo_parcelamento,
			'desconto_pontualidade' => $this->desconto_pontualidade,
			'status' => $this->status,

		];
		if (self::temColunaBolsista()) {
			$dadosUpdate['bolsista'] = !empty($this->bolsista) ? 1 : 0;
		}
		if (self::temColunaEncargos()) {
			$dadosUpdate['multa_atraso_pct'] = round(max(0, (float)($this->multa_atraso_pct ?? 2)), 2);
			$dadosUpdate['juros_mora_pct_mes'] = round(max(0, (float)($this->juros_mora_pct_mes ?? 1)), 2);
			$dadosUpdate['multa_cancelamento_pct'] = round(max(0, (float)($this->multa_cancelamento_pct ?? 10)), 2);
			$dadosUpdate['carencia_dias'] = max(0, (int)($this->carencia_dias ?? 7));
		}
		return (new Database('matriculas'))->update('id = '.$this->id, $dadosUpdate);

	}

	//CANCELA A MATRICULA NO BANCO DE DADOS
	public function cancelar(?string $dataCancelamento = null){

		$dados = ['status' => 3];
		if (self::temColunaDataCancelamento()) {
			$dataCancelamento = trim((string)($dataCancelamento ?: date('Y-m-d')));
			if ($dataCancelamento !== '') {
				$dados['data_cancelamento'] = $dataCancelamento;
			}
		}

		return (new Database('matriculas'))->update('id = '.$this->id, $dados);

	}

	//RETORNA UM DEPOIMENTO COM BASE NO ID
	public static function getMatriculaById($id){

		return self::getMatriculas('id = '.$id)->fetchObject(self::class);

	}

	//RETORNA DEPOIMENTOS
	public static function getMatriculas($where=null,$order=null,$limit=null,$fields='*',$innerJoin=null,$group=null){

		return (new Database('matriculas'))->select($where,$order,$limit,$fields,$innerJoin,$group);
	}


	// Campo de data usado nos relatórios (fallback para inicio quando matriculado_em inválido)
	public static function campoDataMatricula($alias = 'matriculas'){
		return 'COALESCE(NULLIF('.$alias.'.matriculado_em, "0000-00-00"), '.$alias.'.inicio)';
	}

	public static function getCursosMaisMatriculadosMes(
		int $id_admin,
		?int $mes = null,
		?int $ano = null,
		$limit = null
	){
    // Mês e ano atual por padrão
		$mes = $mes ?? date('m');
		$ano = $ano ?? date('Y');

    // Intervalo de datas (performático)
		$dataInicio = $ano . '-' . str_pad($mes, 2, '0', STR_PAD_LEFT) . '-01';
		$dataFim = date('Y-m-d', strtotime($dataInicio . ' +1 month'));

		$innerJoin = '
		INNER JOIN trilhas 
		ON trilhas.id = matriculas.id_trilha
		';

		$fields = '
		trilhas.id,
		trilhas.nome AS curso,
		COUNT(matriculas.id) AS total_matriculas
		';

		$dataCampo = self::campoDataMatricula('matriculas');

		$where = '
		matriculas.id_admin = ' . (int)$id_admin . '
		AND ' . $dataCampo . ' >= "' . $dataInicio . '"
		AND ' . $dataCampo . ' < "' . $dataFim . '"
		';

		$group = 'trilhas.id';
		$order = 'total_matriculas DESC';
		$limit = $limit ?: null;


		return (new Database('matriculas'))->select(
			$where,
			$order,
			$limit,
			$fields,
			$innerJoin,
			$group
		);
	}

}
