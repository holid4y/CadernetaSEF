<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ContactLinks;
use PHPUnit\Framework\TestCase;

class ContactLinksTest extends TestCase
{
    public function test_contacts_are_normalized_and_malformed_values_are_rejected(): void
    {
        $this->assertSame('5551991251538', ContactLinks::phone('(51) 99125-1538'));
        $this->assertSame('555135952211', ContactLinks::phone('+55 51 3595-2211'));
        $this->assertSame('14155552671', ContactLinks::phone('+1 415 555 2671'));
        foreach (['1234', '(20) 99999-9999', '(51) 12345-6789', '+55 912345678', 'abc51991251538', '00000000000'] as $invalid) {
            $this->assertNull(ContactLinks::whatsapp($invalid));
        }
        foreach (['a@example.com?bcc=evil@example.com', "a@example.com\r\nBcc:evil@example.com", 'a..b@example.com', 'bad'] as $invalid) {
            $this->assertNull(ContactLinks::email($invalid));
        }
    }

    public function test_email_composition_does_not_override_the_employee_sender(): void
    {
        $url = ContactLinks::email('ana@example.com', 'Olá & teste', "Linha 1\nLinha 2");
        $this->assertStringStartsWith('mailto:ana@example.com?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame("Linha 1\nLinha 2", $query['body']);
        $this->assertArrayNotHasKey('from', $query);
    }

    public function test_the_message_can_also_be_written_in_gmail(): void
    {
        $url = ContactLinks::gmail(' ana+sef@example.com ', 'Olá & teste', "Linha 1\nLinha 2");

        $this->assertStringStartsWith('https://mail.google.com/mail/?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['view' => 'cm', 'fs' => '1', 'to' => 'ana+sef@example.com', 'su' => 'Olá & teste', 'body' => "Linha 1\nLinha 2"], $query);
        $this->assertNull(ContactLinks::gmail('a@example.com?bcc=evil@example.com'));
    }
}
