<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class EstadoCidades {

	public static function getEstados($where = null,$order = null,$limit = null,$fields = '*'){

		return (new Database('estados'))->select($where,$order,$limit,$fields);
	}

	public static function getCidadesByEstado($id){

		return self::getCidades('estados_id = '.$id)->fetchObject(self::class);

	}

	public static function getCidades($where = null,$order = null,$limit = null,$fields = '*'){

		return (new Database('cidades'))->select($where,$order,$limit,$fields);
	}

}