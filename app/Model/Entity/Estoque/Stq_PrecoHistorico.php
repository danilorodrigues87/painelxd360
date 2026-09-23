<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_PrecoHistorico
{
    public $id;
    public $id_produto;
    public $valor_custo;
    public $valor_venda;
    public $id_admin;
    public $created_at;

    public static function getByProduto(int $idProduto)
    {
        return (new Database('stq_preco_historico'))
            ->select(
                'id_produto = ' . $idProduto,
                'created_at DESC'
            );
    }

    public static function registrar(
        int $idProduto,
        float $valorCusto,
        float $valorVenda,
        int $idAdmin
    ): bool {
        (new Database('stq_preco_historico'))->insert([
            'id_produto'  => $idProduto,
            'valor_custo' => $valorCusto,
            'valor_venda' => $valorVenda,
            'id_admin'    => $idAdmin,
            'created_at'  => date('Y-m-d H:i:s')
        ]);

        return true;
    }
}
