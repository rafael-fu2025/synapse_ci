<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UniversityEmail;
use PHPUnit\Framework\TestCase;

/**
 * UniversityEmail contract tests — pins the university account
 * convention: all given names concatenated + '.' + the concatenated
 * surname + '@foundationu.com', no middle names, no spaces, ASCII
 * letters only. The MIS API never returns an email, so this derivation
 * is the only source of the address for MIS-provisioned users.
 */
final class UniversityEmailTest extends TestCase
{
    public function testDerivesTheDocumentedExample(): void
    {
        // The exact example from the account convention: compound given
        // name concatenates, surname follows the single dot.
        $this->assertSame(
            'johnlloyd.macias@foundationu.com',
            UniversityEmail::derive('John Lloyd', 'Macias')
        );
    }

    public function testSecondAndThirdGivenNamesConcatenate(): void
    {
        $this->assertSame(
            'maryannegrace.delacruzreyes@foundationu.com',
            UniversityEmail::derive('Mary Anne Grace', 'Dela Cruz-Reyes')
        );
    }

    public function testNormalizesCapsAndCompoundSurnames(): void
    {
        // MIS payloads arrive ALL-CAPS with embedded spaces.
        $this->assertSame(
            'juan.delacruz@foundationu.com',
            UniversityEmail::derive('JUAN', 'DELA CRUZ')
        );
    }

    public function testTransliteratesDiacritics(): void
    {
        $this->assertSame(
            'jose.munoz@foundationu.com',
            UniversityEmail::derive('José', 'Muñoz')
        );
    }

    public function testUppercaseDiacriticsFoldBeforeTransliteration(): void
    {
        // MIS payloads arrive ALL-CAPS, so 'Ñ' — not 'ñ' — is what reaches
        // lettersOnly(). The table holds lowercase keys only and the address
        // is lowercase, so the fold has to happen first; an uppercase
        // replacement letter would instead be stripped by the [^a-z] filter
        // and yield "muoz".
        $this->assertSame(
            'jose.munoz@foundationu.com',
            UniversityEmail::derive('JOSÉ', 'MUÑOZ')
        );
    }

    public function testStripsGenerationalSuffixes(): void
    {
        $this->assertSame(
            'juan.macias@foundationu.com',
            UniversityEmail::derive('Juan', 'Macias Jr.')
        );
        $this->assertSame(
            'ana.cruz@foundationu.com',
            UniversityEmail::derive('Ana', 'CRUZ III')
        );
    }

    public function testStripsApostrophesAndHyphens(): void
    {
        $this->assertSame(
            'annamarie.obrien@foundationu.com',
            UniversityEmail::derive('Anna-Marie', "O'Brien")
        );
    }

    public function testReturnsNullWhenEitherNameIsMissing(): void
    {
        $this->assertNull(UniversityEmail::derive(null, 'Macias'));
        $this->assertNull(UniversityEmail::derive('John', null));
        $this->assertNull(UniversityEmail::derive('', '  '));
        $this->assertNull(UniversityEmail::derive(null, null));
    }

    public function testMiddleNameIsNeverPassedByDesign(): void
    {
        // Guard the API shape: derive() takes exactly two arguments so a
        // PH middle/maiden name can never leak into the address.
        $reflection = new \ReflectionMethod(UniversityEmail::class, 'derive');
        $this->assertCount(2, $reflection->getParameters());
    }

    public function testPersonModelPopulatesDerivedEmailWhenMissing(): void
    {
        $model = new \App\Models\PersonModel();
        $reflection = new \ReflectionMethod($model, 'populateDerivedEmail');
        $reflection->setAccessible(true);

        // Singleton with missing email
        $singleton = $reflection->invoke($model, [
            'singleton' => true,
            'data'      => [
                'first_name' => 'Juan',
                'last_name'  => 'Dela Cruz',
                'email'      => null,
            ],
        ]);
        $this->assertSame('juan.delacruz@foundationu.com', $singleton['data']['email']);

        // Singleton with existing email should not be overwritten
        $existing = $reflection->invoke($model, [
            'singleton' => true,
            'data'      => [
                'first_name' => 'Juan',
                'last_name'  => 'Dela Cruz',
                'email'      => 'custom@foundationu.com',
            ],
        ]);
        $this->assertSame('custom@foundationu.com', $existing['data']['email']);

        // Multiple rows with missing and existing emails
        $multiple = $reflection->invoke($model, [
            'singleton' => false,
            'data'      => [
                [
                    'first_name' => 'Elena',
                    'last_name'  => 'Bautista',
                    'email'      => null,
                ],
                [
                    'first_name' => 'Roberto',
                    'last_name'  => 'Hernandez',
                    'email'      => 'custom.hernandez@foundationu.com',
                ],
            ],
        ]);
        $this->assertSame('elena.bautista@foundationu.com', $multiple['data'][0]['email']);
        $this->assertSame('custom.hernandez@foundationu.com', $multiple['data'][1]['email']);
    }
}
