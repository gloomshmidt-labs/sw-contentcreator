<?php declare(strict_types=1);

namespace ContentCreator\Twig;

use ContentCreator\Service\FaqParser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FaqExtension extends AbstractExtension
{
    public function __construct(private readonly FaqParser $faqParser)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_creator_faq_items', fn (?string $html): array => $this->faqParser->parse($html)),
        ];
    }
}
