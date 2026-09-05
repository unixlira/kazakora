<?php

namespace App\Modules\WhatsApp\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class WhatsAppSettings
{
    public const PREFIX = 'whatsapp.';

    private const DEFAULTS = [
        'enabled' => '0',
        'auto_reply_enabled' => '0',
        'sandbox_mode' => '1',
        'attendant_name' => 'Manuela',
        'brand_name' => 'KazaKora',
        'tone' => 'consultivo',
        'max_questions_before_close' => '2',
        'store_base_url' => 'https://kazakora.devlira.com.br',
        'welcome_message' => 'Oi, eu sou a Manuela da KazaKora. Me conta o que você está procurando que eu te ajudo.',
        'outside_hours_message' => 'Agora estamos fora do horário, mas já deixei sua mensagem registrada. Assim que possível seguimos por aqui.',
        'closing_template' => 'Pelo que você me contou, esse modelo parece o caminho mais seguro. Quer que eu te mande o link certo pra compra?',
        'handoff_keywords' => 'reclamação,atrasou,defeito,garantia,troca,cancelar,reembolso,processo,procon,humano,atendente,pessoa',
        'priority_categories' => 'ferramentas,casa,utilidades,segurança residencial,eletrodomésticos simples',
        'forbidden_promises' => 'não prometer desconto, estoque, prazo, garantia, reembolso ou brinde sem confirmação no sistema',
        'business_hours' => 'Segunda a sexta, 9h às 18h',
        'verify_token' => '',
    ];

    public function all(): array
    {
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = Setting::get(self::PREFIX.$key, $default);
        }

        if (! filled($settings['verify_token'])) {
            $settings['verify_token'] = $this->ensureVerifyToken();
        }

        $settings['enabled'] = $this->asBool($settings['enabled']);
        $settings['auto_reply_enabled'] = $this->asBool($settings['auto_reply_enabled']);
        $settings['sandbox_mode'] = $this->asBool($settings['sandbox_mode']);
        $settings['max_questions_before_close'] = max(1, min(3, (int) $settings['max_questions_before_close']));

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return Setting::get(self::PREFIX.$key, $default ?? self::DEFAULTS[$key] ?? null);
    }

    public function bool(string $key): bool
    {
        return $this->asBool($this->get($key, self::DEFAULTS[$key] ?? '0'));
    }

    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            Setting::set(self::PREFIX.$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
    }

    public function ensureVerifyToken(): string
    {
        $existing = Setting::get(self::PREFIX.'verify_token');

        if (filled($existing)) {
            return $existing;
        }

        $token = Str::random(48);
        Setting::set(self::PREFIX.'verify_token', $token);

        return $token;
    }

    public function isReadyToSend(): bool
    {
        return filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    private function asBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
