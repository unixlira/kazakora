<?php

namespace App\Console\Commands;

use App\Modules\Admin\Support\AlertasAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Abre um alerta no sino do admin (Sistema › Alertas) vindo de fora da aplicação, como o gestor de
 * tráfego que roda no computador do Lira (pedido de 25/11/2026). Com --email, manda o mesmo texto por e-mail.
 */
class DispararAlertaAdmin extends Command
{
    protected $signature = 'alertas:disparar {tipo} {chave} {titulo} {mensagem} {--link=} {--email=*}';

    protected $description = 'Abre (ou atualiza) um alerta do admin e, se pedido, manda o mesmo texto por e-mail';

    public function handle(AlertasAdmin $alertas): int
    {
        $tipo = (string) $this->argument('tipo');
        if (! isset(AlertasAdmin::TIPOS[$tipo])) {
            $this->error("Tipo de alerta desconhecido: {$tipo}");

            return self::INVALID;
        }

        $titulo = (string) $this->argument('titulo');
        $mensagem = (string) $this->argument('mensagem');
        $alerta = $alertas->disparar($tipo, (string) $this->argument('chave'), $titulo, $mensagem, $this->option('link') ?: null);
        $this->line($alerta ? "alerta {$alerta->id}" : 'alerta desligado nas configurações');

        foreach ((array) $this->option('email') as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            rescue(fn () => retry(2, fn () => Mail::raw($mensagem.($this->option('link') ? "\n\n".$this->option('link') : ''),
                fn ($m) => $m->to($email)->subject('[KazaKora] '.$titulo)), 3000));
            $this->line("e-mail para {$email}");
        }

        return self::SUCCESS;
    }
}
