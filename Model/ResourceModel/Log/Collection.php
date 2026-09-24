<?php
namespace Meetanshi\AiProductStudio\Model\ResourceModel\Log;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Meetanshi\AiProductStudio\Model\Log;
use Meetanshi\AiProductStudio\Model\ResourceModel\Log as LogResource;

/**
 * Class Collection
 *
 * Collection model for AI Product Studio request logs.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'log_id';

    /**
     * Initialize collection.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(Log::class, LogResource::class);
    }
}
