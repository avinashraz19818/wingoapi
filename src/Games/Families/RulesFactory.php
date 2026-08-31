<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;

/**
 * Resolves the rule engine for a lottery family (one shared instance each).
 */
class RulesFactory
{
    /** @var array<string,FamilyRules> */
    private array $cache = [];

    public function forFamily(string $family): FamilyRules
    {
        $key = strtolower($family);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        switch ($key) {
            case 'wingo':
                $rules = new WinGoRules();
                break;
            case 'trxwingo':
                $rules = new TrxWinGoRules();
                break;
            case 'k3':
                $rules = new K3Rules();
                break;
            case 'd5':
            case '5d':
                $rules = new D5Rules();
                break;
            case 'motorace':
                $rules = new MotoRaceRules();
                break;
            default:
                throw ApiException::notFound("No rule engine for family '{$family}'");
        }

        return $this->cache[$key] = $rules;
    }

    public function forGame(GameDefinition $game): FamilyRules
    {
        return $this->forFamily($game->family);
    }
}
