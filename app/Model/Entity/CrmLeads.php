<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmLeads{

	public
		$id,
		$id_admin,
		$usuario_id,
		$id_usuario,
		$visibilidade,
		$funil_id,
		$nome,
		$whatsapp,
		$curso_interesse,
		$origem,
		$email,
		$bairro,
		$cidade,
		$idade,
		$responsavel_nome,
		$valor_estimado,
		$data_cadastro,
		$status,
		$motivo_perda,
		$id_instancia_wa,
		$status_wa;

	public static function getLeadById($id){
		return self::getLeads('id = '.(int)$id)->fetchObject(self::class);
	}

	public static function colunaIdUsuarioExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW COLUMNS FROM crm_leads LIKE 'id_usuario'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getLeads($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null,$group = null){
		return (new Database('crm_leads'))->select($where,$order,$limit,$fields,$innerJoin,$group);
	}

	public function cadastrar(){
		$dados = [
			'id_admin'         => $this->id_admin,
			'usuario_id'       => $this->usuario_id,
			'visibilidade'     => $this->visibilidade ?? 'publico',
			'funil_id'         => $this->funil_id,
			'nome'             => $this->nome,
			'whatsapp'         => $this->whatsapp,
			'curso_interesse'  => $this->curso_interesse,
			'origem'           => $this->origem,
			'email'            => $this->email,
			'bairro'           => $this->bairro,
			'cidade'           => $this->cidade,
			'idade'            => $this->idade,
			'responsavel_nome' => $this->responsavel_nome,
			'valor_estimado'   => $this->valor_estimado,
			'data_cadastro'    => $this->data_cadastro ?? date('Y-m-d H:i:s'),
			'status'           => $this->status ?? 'novo',
			'motivo_perda'     => $this->motivo_perda ?? null,
			'id_instancia_wa'  => $this->id_instancia_wa ?? null,
			'status_wa'        => $this->status_wa ?? 'pendente'
		];
		if (self::colunaIdUsuarioExiste() && !empty($this->id_usuario)) {
			$dados['id_usuario'] = (int)$this->id_usuario;
		}
		$obDatabase = new Database('crm_leads');
		$this->id = $obDatabase->insert($dados);
		return true;
	}

	public function atualizarStatus(){
		$dados = [
			'status' => $this->status
		];

		if(isset($this->usuario_id)){
			$dados['usuario_id'] = $this->usuario_id;
		}

		if($this->status === 'perdido'){
			$dados['motivo_perda'] = $this->motivo_perda;
		} else {
			$dados['motivo_perda'] = null;
		}

		return (new Database('crm_leads'))->update('id = '.(int)$this->id, $dados);
	}

	public function atualizarDados(){
		$dados = [
			'nome'             => $this->nome,
			'whatsapp'         => $this->whatsapp,
			'curso_interesse'  => $this->curso_interesse,
			'origem'           => $this->origem,
			'email'            => $this->email,
			'bairro'           => $this->bairro,
			'cidade'           => $this->cidade,
			'idade'            => $this->idade,
			'responsavel_nome' => $this->responsavel_nome,
			'valor_estimado'   => $this->valor_estimado
		];

		if(isset($this->funil_id)){
			$dados['funil_id'] = $this->funil_id;
		}

		return (new Database('crm_leads'))->update('id = '.(int)$this->id, $dados);
	}

}

