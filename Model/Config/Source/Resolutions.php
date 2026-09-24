<?php
namespace Meetanshi\AiProductStudio\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class Resolutions
 *
 * Provides resolution options for AI Product Studio configuration.
 */
class Resolutions implements OptionSourceInterface
{
    /**
     * Get available resolution options.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => '512', 'label' => '512x512'],
            ['value' => '768', 'label' => '768x768'],
            ['value' => '1024', 'label' => '1K (1024x1024)'],
            ['value' => 'custom', 'label' => 'Custom']
        ];
    }
}
