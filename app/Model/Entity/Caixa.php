<?php

namespace App\Model\Entity;
use App\Model\Db\Database;
use App\Session\User\Login as SessionUser;

class Caixa{
 
	public	$id,
				$id_admin,
				$id_usuario,
				$descricao,
				$tipo_transacao,
				$valor,
				$vencimento,
				$referencia,
				$id_ref,
				$id_acordo,
				$txt_id,
				$ultima_alteracao,
				$pix_copia_cola,
				$nosso_numero,
				$valor_pago,
				$status,
				$data_pagamento,
				$tipo_pagamento;


	//RETORNA COM BASE NO ID
	public static function getCaixaById($id){

		return self::getCaixa('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO DE DADOS
	public function lancarMovimentacao(){

		$userLogedData = SessionUser::getUserLogedData();
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('caixa');
		$dados = [
			'id_admin' => $this->id_admin,
			'id_usuario' => $userLogedData['usuario']['id'],
			'descricao' => $this->descricao,
			'tipo_transacao' => $this->tipo_transacao,
			'tipo_pagamento' => $this->tipo_pagamento,
			'ultima_alteracao' => date('Y-m-d H:i:s'),
			'valor' => $this->valor,
			'valor_pago' => $this->valor_pago,
			'vencimento' => $this->vencimento,
			'data_pagamento' => $this->data_pagamento,
			'referencia' => $this->referencia,
			'id_ref' => $this->id_ref,
			'txt_id' => $this->txt_id,
			'status' => $this->status,
			'pix_copia_cola' => $this->pix_copia_cola,
			'nosso_numero' => $this->nosso_numero
		];
		if (FinanceiroAcordo::caixaTemIdAcordo() && $this->id_acordo !== null && $this->id_acordo !== '') {
			$dados['id_acordo'] = (int)$this->id_acordo;
		}
		$this->id = $obDatabase->insert($dados);
		
		return true; 
	} 

	//RETORNA A INFORMAÇÃO
	public static function getCaixa($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null,$group = null){
		return (new Database('caixa'))->select($where,$order,$limit,$fields,$innerJoin,$group);
	}

	//ATUALIZA NO BANCO DE DADOS
	public function atualizar(){

		$userLogedData = SessionUser::getUserLogedData();

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('caixa'))->update('id = '.$this->id,[
			'id_usuario' => $userLogedData['usuario']['id'],
			'valor_pago' => $this->valor_pago,
			'status' => $this->status,
			'data_pagamento' => $this->data_pagamento,
			'tipo_pagamento' => $this->tipo_pagamento,
			'ultima_alteracao' => date('Y-m-d H:i')
			
		]);
 
	}

	/** Atualiza dados PIX após criar cobrança no gateway. */
	public function atualizarPix() {
		$id = (int)($this->id ?? 0);
		if ($id <= 0) {
			return false;
		}
		return (new Database('caixa'))->update('id = '.$id, [
			'txt_id' => (string)($this->txt_id ?? ''),
			'pix_copia_cola' => (string)($this->pix_copia_cola ?? ''),
			'ultima_alteracao' => date('Y-m-d H:i:s'),
		]);
	}

	// DA BAIXA VIA API (webhook PIX)
	public function baixaViaApi() {
		if (!isset($this->txt_id) || !isset($this->valor_pago) || !isset($this->data_pagamento) || !isset($this->ultima_alteracao)) {
			throw new \Exception('Dados incompletos para a baixa via API');
		}

		$txtId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$this->txt_id);
		if ($txtId === '') {
			throw new \Exception('txt_id inválido');
		}

		$where = "txt_id = '".$txtId."'";
		$idAdmin = (int)($this->id_admin ?? 0);
		if ($idAdmin > 0) {
			$where .= ' AND id_admin = '.$idAdmin;
		}
		if (!empty($this->id)) {
			$where = 'id = '.(int)$this->id.($idAdmin > 0 ? ' AND id_admin = '.$idAdmin : '');
		}

		return (new Database('caixa'))->update($where, [
			'valor_pago' => $this->valor_pago,
			'status' => \App\Common\Helpers\FinanceiroAlunoHelper::STATUS_PAGO,
			'id_usuario' => 0,
			'tipo_pagamento' => 'Pix',
			'data_pagamento' => $this->data_pagamento,
			'ultima_alteracao' => $this->ultima_alteracao,
			'txt_id' => $txtId,
		]);
	}



	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('caixa'))->delete('id = '.$this->id);

	}

	//EXCLUI DO BANCO DE DADOS
	/** @deprecated Preferir MatriculaStatusHelper::cancelarParcelasAbertas (baixa R$ 0). */
	public function excluirMatricula(){

		return (new Database('caixa'))->delete('id_ref = '.$this->id_ref.' AND status = 0');

	}


}