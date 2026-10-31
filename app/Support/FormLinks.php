<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Appointment;
use App\Models\ExternalForm;

final class FormLinks
{
    public static function allowed(?string $url): bool
    {
        if (! $url || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443
            && in_array(strtolower($parts['host'] ?? ''), config('services.forms.allowed_hosts', []), true);
    }

    public static function open(ExternalForm $form): ?string
    {
        return config('services.forms.enabled') && $form->status !== 'deleted' && self::allowed($form->url)
            ? $form->url : null;
    }

    /**
     * The one button an appointment shows for its form: the latest form,
     * done or still to do, or a new one when there is none yet.
     *
     * @return ?array{label: string, url: string}
     */
    public static function action(Appointment $appointment, bool $canWrite): ?array
    {
        foreach ($appointment->externalForms as $form) {
            $url = self::open($form);

            if ($url !== null) {
                return ['label' => $form->actionLabel(), 'url' => $url];
            }
        }

        $create = $canWrite ? self::create($appointment) : null;

        return $create === null ? null : ['label' => 'Preencher formulário', 'url' => $create];
    }

    public static function create(Appointment $appointment): ?string
    {
        $url = config('services.forms.create_url');

        if (! config('services.forms.enabled') || ! self::allowed($url)) {
            return null;
        }

        $parts = explode('#', $url, 2);
        $base = explode('?', $parts[0], 2);
        parse_str($base[1] ?? '', $query);
        $query['appointment_id'] = $appointment->integration_id;

        return $base[0].'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986)
            .(isset($parts[1]) ? '#'.$parts[1] : '');
    }
}
