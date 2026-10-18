<?php

namespace App\Modules\App\Services;

use App\Models\User;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Checkout\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Exclusão da conta pelo próprio cliente no app (2026-10-18, exigência da
 * Apple para app com cadastro). O site não tem exclusão pelo cliente, e a
 * trava "tem_pedido" do Meu Perfil é do endereço; aqui a conta COM pedido
 * também pode sair, por anonimização:
 *
 * - todos os tokens (login do app) e tokens de notificação apagados;
 * - nome, e-mail, CPF, telefone, nascimento e foto da conta apagados (o
 *   e-mail vira um endereço inválido único, o CPF fica nulo), senha
 *   trocada por uma aleatória e conta marcada como apagada (soft delete);
 * - endereços sem pedido apagados; os usados em pedido ficam (o pedido
 *   aponta para eles) mas sem nome, telefone, rua e número;
 * - favoritos, carrinho guardado e pedidos de nova senha apagados.
 *
 * Pedidos, notas fiscais e pagamentos ficam como estão pela obrigação
 * fiscal (5 anos): os dados de entrega gravados no pedido (shipping_*) são
 * os da NF-e emitida e não mudam. O pedido continua apontando para a conta
 * apagada, que não tem mais dado pessoal. Avaliações publicadas ficam, com o
 * nome "Conta excluída".
 */
class ExcluirContaDoApp
{
    public const NOME = 'Conta excluída';

    public function excluir(User $user): void
    {
        DB::transaction(function () use ($user) {
            $emailAntigo = $user->email;
            $avatar = $user->avatar_path;

            $user->tokens()->delete();
            $user->pushTokens()->delete();
            Favorite::query()->where('user_id', $user->id)->delete();
            CartSnapshot::query()->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $emailAntigo)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            Address::query()->where('user_id', $user->id)->get()->each(function (Address $address) {
                if (! $address->orders()->exists()) {
                    $address->delete();

                    return;
                }

                $address->forceFill([
                    'label' => null,
                    'recipient_name' => self::NOME,
                    'phone' => '',
                    'street' => '',
                    'number' => '',
                    'complement' => null,
                    'is_default' => false,
                ])->save();
            });

            $user->forceFill([
                'name' => self::NOME,
                'email' => 'excluida-'.$user->id.'-'.Str::lower(Str::random(8)).'@conta-excluida.invalid',
                'cpf' => null,
                'phone' => null,
                'birth_date' => null,
                'avatar_path' => null,
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'recebe_promocoes' => false,
            ])->save();
            $user->delete();

            if ($avatar) {
                DB::afterCommit(fn () => rescue(fn () => Storage::disk('public')->delete($avatar), null, false));
            }
        });
    }
}
