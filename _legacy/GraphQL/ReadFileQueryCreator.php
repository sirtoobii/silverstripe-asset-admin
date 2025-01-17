<?php
namespace SilverStripe\AssetAdmin\GraphQL;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use GraphQL\Type\Definition\UnionType;
use SilverStripe\GraphQL\Pagination\PaginatedQueryCreator;
use SilverStripe\GraphQL\Pagination\Connection;
use SilverStripe\Versioned\Versioned;

if (!class_exists(PaginatedQueryCreator::class)) {
    return;
}

/**
 * @skipUpgrade
 * @deprecated 4.8..5.0 Use silverstripe/graphql:^4 functionality.
 */
class ReadFileQueryCreator extends PaginatedQueryCreator
{

    /**
     * @var UnionType
     */
    private $resultType;

    public function attributes()
    {
        return [
            'name' => 'readFiles'
        ];
    }

    public function createConnection()
    {
        return ReadFileConnection::create('readFiles')
            ->setConnectionType(function () {
                return $this->createResultType();
            })
            ->setArgs(function () {
                return [
                    'filter' => [
                        'type' => $this->manager->getType('FileFilterInput')
                    ]
                ];
            })
            ->setSortableFields(['ID', 'Title', 'Created', 'LastEdited'])
            ->setConnectionResolver(array($this, 'resolveConnection'));
    }

    public function resolveConnection($object, array $args, $context, $info)
    {
        $filter = (!empty($args['filter'])) ? $args['filter'] : [];

        // Permission checks
        $parent = Folder::singleton();
        if (isset($filter['parentId']) && $filter['parentId'] !== 0) {
            $parent = Folder::get()->byID($filter['parentId']);
            if (!$parent) {
                throw new \InvalidArgumentException(sprintf(
                    '%s#%s not found',
                    Folder::class,
                    $filter['parentId']
                ));
            }
        }
        if (!$parent->canView($context['currentUser'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s#%s view access not permitted',
                Folder::class,
                $parent->ID
            ));
        }

        if (isset($filter['recursive']) && $filter['recursive']) {
            throw new \InvalidArgumentException((
               'The "recursive" flag can only be used for the "children" field'
            ));
        }

        // Filter list
        $list = Versioned::get_by_stage(File::class, Versioned::DRAFT);
        $filterInputType = FileFilterInputTypeCreator::create($this->manager);
        $list = $filterInputType->filterList($list, $filter);

        // Permission checks
        $list = $list->filterByCallback(function (File $file) use ($context) {
            return $file->canView($context['currentUser']);
        });

        return $list;
    }

    private function createResultType()
    {
        if ($this->resultType) {
            return $this->resultType;
        }
        $this->resultType = new UnionType([
            'name' => 'Result',
            'types' => [
                $this->manager->getType('File'),
                $this->manager->getType('Folder')
            ],
            'resolveType' => function ($object) {
                if ($object instanceof Folder) {
                    return $this->manager->getType('Folder');
                }
                if ($object instanceof File) {
                    return $this->manager->getType('File');
                }
                return null;
            }
        ]);

        return $this->resultType;
    }
}
