<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryExpressionVisitor;
use InvalidArgumentException;
use RuntimeException;

use function array_keys;
use function array_merge;
use function array_unshift;
use function assert;
use function func_get_args;
use function func_num_args;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;
use function key;
use function reset;
use function sprintf;
use function strpos;
use function strrpos;
use function substr;

/**
 * This class is responsible for building DQL query strings via an object oriented
 * PHP interface.
 */
class QueryBuilder
{
    /* The query types. */
    public const SELECT = 0;
    public const DELETE = 1;
    public const UPDATE = 2;

    /* The builder states. */
    public const STATE_DIRTY = 0;
    public const STATE_CLEAN = 1;

    /**
     * The EntityManager used by this QueryBuilder.
     *
     * @var EntityManagerInterface
     */
    private $_em;

    /**
     * The array of DQL parts collected.
     *
     * @psalm-var array<string, mixed>
     */
    private $_dqlParts = [
        'distinct' => false,
        'select'  => [],
        'from'    => [],
        'join'    => [],
        'set'     => [],
        'where'   => null,
        'groupBy' => [],
        'having'  => null,
        'orderBy' => [],
    ];

    /**
     * The type of query this is. Can be select, update or delete.
     *
     * @var int
     */
    private $_type = self::SELECT;

    /**
     * The state of the query object. Can be dirty or clean.
     *
     * @var int
     */
    private $_state = self::STATE_CLEAN;

    /**
     * The complete DQL string for this query.
     *
     * @var string
     */
    private $_dql;

    /**
     * The query parameters.
     *
     * @var ArrayCollection
     * @psalm-var ArrayCollection<int, Parameter>
     */
    private $parameters;

    /**
     * The index of the first result to retrieve.
     *
     * @var int|null
     */
    private $_firstResult = null;

    /**
     * The maximum number of results to retrieve.
     *
     * @var int|null
     */
    private $_maxResults = null;

    /**
     * Keeps root entity alias names for join entities.
     *
     * @psalm-var array<string, string>
     */
    private $joinRootAliases = [];

     /**
      * Whether to use second level cache, if available.
      *
      * @var bool
      */
    protected $cacheable = false;

    /**
     * Second level cache region name.
     *
     * @var string|null
     */
    protected $cacheRegion;

    /**
     * Second level query cache mode.
     *
     * @var int|null
     */
    protected $cacheMode;

    /** @var int */
    protected $lifetime = 0;

    /**
     * Initializes a new <tt>QueryBuilder</tt> that uses the given <tt>EntityManager</tt>.
     *
     * @param EntityManagerInterface $em The EntityManager to use.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->_em        = $em;
        $this->parameters = new ArrayCollection();
    }

    /**
     * Gets an ExpressionBuilder used for object-oriented construction of query expressions.
     * This producer method is intended for convenient inline usage. Example:
     *
     * <code>
     *     $qb = $em->createQueryBuilder();
     *     $qb
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where($qb->expr()->eq('u.id', 1));
     * </code>
     *
     * For more complex expression construction, consider storing the expression
     * builder object in a local variable.
     *
     * @return Query\Expr
     */
    public function expr()
    {
        return $this->_em->getExpressionBuilder();
    }

    /**
     * Enable/disable second level query (result) caching for this query.
     *
     * @param bool $cacheable
     *
     * @return static
     */
    public function setCacheable($cacheable)
    {
        $this->cacheable = (bool) $cacheable;

        return $this;
    }

    /**
     * @return bool TRUE if the query results are enable for second level cache, FALSE otherwise.
     */
    public function isCacheable()
    {
        return $this->cacheable;
    }

    /**
     * @param string $cacheRegion
     *
     * @return static
     */
    public function setCacheRegion($cacheRegion)
    {
        $this->cacheRegion = (string) $cacheRegion;

        return $this;
    }

    /**
     * Obtain the name of the second level query cache region in which query results will be stored
     *
     * @return string|null The cache region name; NULL indicates the default region.
     */
    public function getCacheRegion()
    {
        return $this->cacheRegion;
    }

    /**
     * @return int
     */
    public function getLifetime()
    {
        return $this->lifetime;
    }

    /**
     * Sets the life-time for this query into second level cache.
     *
     * @param int $lifetime
     *
     * @return static
     */
    public function setLifetime($lifetime)
    {
        $this->lifetime = (int) $lifetime;

        return $this;
    }

    /**
     * @return int
     */
    public function getCacheMode()
    {
        return $this->cacheMode;
    }

    /**
     * @param int $cacheMode
     *
     * @return static
     */
    public function setCacheMode($cacheMode)
    {
        $this->cacheMode = (int) $cacheMode;

        return $this;
    }

    /**
     * Gets the type of the currently built query.
     *
     * @return int
     */
    public function getType()
    {
        return $this->_type;
    }

    /**
     * Gets the associated EntityManager for this query builder.
     *
     * @return EntityManagerInterface
     */
    public function getEntityManager()
    {
        return $this->_em;
    }

    /**
     * Gets the state of this query builder instance.
     *
     * @return int Either QueryBuilder::STATE_DIRTY or QueryBuilder::STATE_CLEAN.
     */
    public function getState()
    {
        return $this->_state;
    }

    /**
     * Gets the complete DQL string formed by the current specifications of this QueryBuilder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     echo $qb->getDql(); // SELECT u FROM User u
     * </code>
     *
     * @return string The DQL query string.
     */
    public function getDQL()
    {
        if ($this->_dql !== null && $this->_state === self::STATE_CLEAN) {
            return $this->_dql;
        }

        switch ($this->_type) {
            case self::DELETE:
                $dql = $this->getDQLForDelete();
                break;

            case self::UPDATE:
                $dql = $this->getDQLForUpdate();
                break;

            case self::SELECT:
            default:
                $dql = $this->getDQLForSelect();
                break;
        }

        $this->_state = self::STATE_CLEAN;
        $this->_dql   = $dql;

        return $dql;
    }

    /**
     * Constructs a Query instance from the current specifications of the builder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     $q = $qb->getQuery();
     *     $results = $q->execute();
     * </code>
     *
     * @return Query
     */
    public function getQuery()
    {
        $parameters = clone $this->parameters;
        $query      = $this->_em->createQuery($this->getDQL())
            ->setParameters($parameters)
            ->setFirstResult($this->_firstResult)
            ->setMaxResults($this->_maxResults);

        if ($this->lifetime) {
            $query->setLifetime($this->lifetime);
        }

        if ($this->cacheMode) {
            $query->setCacheMode($this->cacheMode);
        }

        if ($this->cacheable) {
            $query->setCacheable($this->cacheable);
        }

        if ($this->cacheRegion) {
            $query->setCacheRegion($this->cacheRegion);
        }

        return $query;
    }

    /**
     * Finds the root entity alias of the joined entity.
     *
     * @param string $alias       The alias of the new join entity
     * @param string $parentAlias The parent entity alias of the join relationship
     */
    private function findRootAlias(string $alias, string $parentAlias): string
    {
        $rootAlias = null;

        if (in_array($parentAlias, $this->getRootAliases())) {
            $rootAlias = $parentAlias;
        } elseif (isset($this->joinRootAliases[$parentAlias])) {
            $rootAlias = $this->joinRootAliases[$parentAlias];
        } else {
            // Should never happen with correct joining order. Might be
            // thoughtful to throw exception instead.
            $rootAlias = $this->getRootAlias();
        }

        $this->joinRootAliases[$alias] = $rootAlias;

        return $rootAlias;
    }

    /**
     * Gets the FIRST root alias of the query. This is the first entity alias involved
     * in the construction of the query.
     *
     * <code>
     * $qb = $em->createQueryBuilder()
     *     ->select('u')
     *     ->from('User', 'u');
     *
     * echo $qb->getRootAlias(); // u
     * </code>
     *
     * @deprecated Please use $qb->getRootAliases() instead.
     *
     * @return string
     *
     * @throws RuntimeException
     */
    public function getRootAlias()
    {
        $aliases = $this->getRootAliases();

        if (! isset($aliases[0])) {
            throw new RuntimeException('No alias was set before invoking getRootAlias().');
        }

        return $aliases[0];
    }

    /**
     * Gets the root aliases of the query. This is the entity aliases involved
     * in the construction of the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *
     *     $qb->getRootAliases(); // array('u')
     * </code>
     *
     * @return mixed[]
     * @psalm-return list<mixed>
     */
    public function getRootAliases()
    {
        $aliases = [];

        foreach ($this->_dqlParts['from'] as &$fromClause) {
            if (is_string($fromClause)) {
                $spacePos = strrpos($fromClause, ' ');
                $from     = substr($fromClause, 0, $spacePos);
                $alias    = substr($fromClause, $spacePos + 1);

                $fromClause = new Query\Expr\From($from, $alias);
            }

            $aliases[] = $fromClause->getAlias();
        }

        return $aliases;
    }

    /**
     * Gets all the aliases that have been used in the query.
     * Including all select root aliases and join aliases
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->join('u.articles','a');
     *
     *     $qb->getAllAliases(); // array('u','a')
     * </code>
     *
     * @return mixed[]
     * @psalm-return list<mixed>
     */
    public function getAllAliases()
    {
        return array_merge($this->getRootAliases(), array_keys($this->joinRootAliases));
    }

    /**
     * Gets the root entities of the query. This is the entity aliases involved
     * in the construction of the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *
     *     $qb->getRootEntities(); // array('User')
     * </code>
     *
     * @return mixed[]
     * @psalm-return list<mixed>
     */
    public function getRootEntities()
    {
        $entities = [];

        foreach ($this->_dqlParts['from'] as &$fromClause) {
            if (is_string($fromClause)) {
                $spacePos = strrpos($fromClause, ' ');
                $from     = substr($fromClause, 0, $spacePos);
                $alias    = substr($fromClause, $spacePos + 1);

                $fromClause = new Query\Expr\From($from, $alias);
            }

            $entities[] = $fromClause->getFrom();
        }

        return $entities;
    }

    /**
     * Sets a query parameter for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter('user_id', 1);
     * </code>
     *
     * @param string|int      $key   The parameter position or name.
     * @param mixed           $value The parameter value.
     * @param string|int|null $type  PDO::PARAM_* or \Doctrine\DBAL\Types\Type::* constant
     *
     * @return static
     */
    public function setParameter($key, $value, $type = null)
    {
        $existingParameter = $this->getParameter($key);

        if ($existingParameter !== null) {
            $existingParameter->setValue($value, $type);

            return $this;
        }

        $this->parameters->add(new Parameter($key, $value, $type));

        return $this;
    }

    /**
     * Sets a collection of query parameters for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id1 OR u.id = :user_id2')
     *         ->setParameters(new ArrayCollection(array(
     *             new Parameter('user_id1', 1),
     *             new Parameter('user_id2', 2)
     *        )));
     * </code>
     *
     * @param ArrayCollection|mixed[] $parameters The query parameters to set.
     * @psalm-param ArrayCollection<int, Parameter>|mixed[] $parameters
     *
     * @return static
     */
    public function setParameters($parameters)
    {
        // BC compatibility with 2.3-
        if (is_array($parameters)) {
            /** @psalm-var ArrayCollection<int, Parameter> $parameterCollection */
            $parameterCollection = new ArrayCollection();

            foreach ($parameters as $key => $value) {
                $parameter = new Parameter($key, $value);

                $parameterCollection->add($parameter);
            }

            $parameters = $parameterCollection;
        }

        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Gets all defined query parameters for the query being constructed.
     *
     * @return ArrayCollection The currently defined query parameters.
     * @psalm-return ArrayCollection<int, Parameter>
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Gets a (previously set) query parameter of the query being constructed.
     *
     * @param mixed $key The key (index or name) of the bound parameter.
     *
     * @return Parameter|null The value of the bound parameter.
     */
    public function getParameter($key)
    {
        $key = Parameter::normalizeName($key);

        $filteredParameters = $this->parameters->filter(
            static function (Parameter $parameter) use ($key): bool {
                $parameterName = $parameter->getName();

                return $key === $parameterName;
            }
        );

        return ! $filteredParameters->isEmpty() ? $filteredParameters->first() : null;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int|null $firstResult The first result to return.
     *
     * @return static
     */
    public function setFirstResult($firstResult)
    {
        $this->_firstResult = $firstResult;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this QueryBuilder.
     *
     * @return int|null The position of the first result.
     */
    public function getFirstResult()
    {
        return $this->_firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int|null $maxResults The maximum number of results to retrieve.
     *
     * @return static
     */
    public function setMaxResults($maxResults)
    {
        $this->_maxResults = $maxResults;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query builder.
     *
     * @return int|null Maximum number of results.
     */
    public function getMaxResults()
    {
        return $this->_maxResults;
    }

    /**
     * Either appends to or replaces a single, generic query part.
     *
     * The available parts are: 'select', 'from', 'join', 'set', 'where',
     * 'groupBy', 'having' and 'orderBy'.
     *
     * @param string              $dqlPartName The DQL part name.
     * @param string|object|array $dqlPart     An Expr object.
     * @param bool                $append      Whether to append (true) or replace (false).
     * @psalm-param string|object|list<string>|array{join: array<int|string, object>} $dqlPart
     *
     * @return static
     */
    public function add($dqlPartName, $dqlPart, $append = false)
    {
        if ($append && ($dqlPartName === 'where' || $dqlPartName === 'having')) {
            throw new InvalidArgumentException(
                "Using \$append = true does not have an effect with 'where' or 'having' " .
                'parts. See QueryBuilder#andWhere() for an example for correct usage.'
            );
        }

        $isMultiple = is_array($this->_dqlParts[$dqlPartName])
            && ! ($dqlPartName === 'join' && ! $append);

        // Allow adding any part retrieved from self::getDQLParts().
        if (is_array($dqlPart) && $dqlPartName !== 'join') {
            $dqlPart = reset($dqlPart);
        }

        // This is introduced for backwards compatibility reasons.
        // TODO: Remove for 3.0
        if ($dqlPartName === 'join') {
            $newDqlPart = [];

            foreach ($dqlPart as $k => $v) {
                $k = is_numeric($k) ? $this->getRootAlias() : $k;

                $newDqlPart[$k] = $v;
            }

            $dqlPart = $newDqlPart;
        }

        if ($append && $isMultiple) {
            if (is_array($dqlPart)) {
                $key = key($dqlPart);

                $this->_dqlParts[$dqlPartName][$key][] = $dqlPart[$key];
            } else {
                $this->_dqlParts[$dqlPartName][] = $dqlPart;
            }
        } else {
            $this->_dqlParts[$dqlPartName] = $isMultiple ? [$dqlPart] : $dqlPart;
        }

        $this->_state = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Specifies an item that is to be returned in the query result.
     * Replaces any previously specified selections, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u', 'p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @param mixed $select The selection expressions.
     *
     * @return static
     */
    public function select($select = null)
    {
        $this->_type = self::SELECT;

        if (empty($select)) {
            return $this;
        }

        $selects = is_array($select) ? $select : func_get_args();

        return $this->add('select', new Expr\Select($selects), false);
    }

    /**
     * Adds a DISTINCT flag to this query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->distinct()
     *         ->from('User', 'u');
     * </code>
     *
     * @param bool $flag
     *
     * @return static
     */
    public function distinct($flag = true)
    {
        $this->_dqlParts['distinct'] = (bool) $flag;

        return $this;
    }

    /**
     * Adds an item that is to be returned in the query result.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->addSelect('p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @param mixed $select The selection expression.
     *
     * @return static
     */
    public function addSelect($select = null)
    {
        $this->_type = self::SELECT;

        if (empty($select)) {
            return $this;
        }

        $selects = is_array($select) ? $select : func_get_args();

        return $this->add('select', new Expr\Select($selects), true);
    }

    /**
     * Turns the query being built into a bulk delete query that ranges over
     * a certain entity type.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->delete('User', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter('user_id', 1);
     * </code>
     *
     * @param string $delete The class/type whose instances are subject to the deletion.
     * @param string $alias  The class/type alias used in the constructed query.
     *
     * @return static
     */
    public function delete($delete = null, $alias = null)
    {
        $this->_type = self::DELETE;

        if (! $delete) {
            return $this;
        }

        return $this->add('from', new Expr\From($delete, $alias));
    }

    /**
     * Turns the query being built into a bulk update query that ranges over
     * a certain entity type.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->update('User', 'u')
     *         ->set('u.password', '?1')
     *         ->where('u.id = ?2');
     * </code>
     *
     * @param string $update The class/type whose instances are subject to the update.
     * @param string $alias  The class/type alias used in the constructed query.
     *
     * @return static
     */
    public function update($update = null, $alias = null)
    {
        $this->_type = self::UPDATE;

        if (! $update) {
            return $this;
        }

        return $this->add('from', new Expr\From($update, $alias));
    }

    /**
     * Creates and adds a query root corresponding to the entity identified by the given alias,
     * forming a cartesian product with any existing query roots.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     * </code>
     *
     * @param string $from    The class name.
     * @param string $alias   The alias of the class.
     * @param string $indexBy The index for the from.
     *
     * @return static
     */
    public function from($from, $alias, $indexBy = null)
    {
        return $this->add('from', new Expr\From($from, $alias, $indexBy), true);
    }

    /**
     * Updates a query root corresponding to an entity setting its index by. This method is intended to be used with
     * EntityRepository->createQueryBuilder(), which creates the initial FROM clause and do not allow you to update it
     * setting an index by.
     *
     * <code>
     *     $qb = $userRepository->createQueryBuilder('u')
     *         ->indexBy('u', 'u.id');
     *
     *     // Is equivalent to...
     *
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u', 'u.id');
     * </code>
     *
     * @param string $alias   The root alias of the class.
     * @param string $indexBy The index for the from.
     *
     * @return static
     *
     * @throws Query\QueryException
     */
    public function indexBy($alias, $indexBy)
    {
        $rootAliases = $this->getRootAliases();

        if (! in_array($alias, $rootAliases)) {
            throw new Query\QueryException(
                sprintf('Specified root alias %s must be set before invoking indexBy().', $alias)
            );
        }

        foreach ($this->_dqlParts['from'] as &$fromClause) {
            assert($fromClause instanceof Expr\From);
            if ($fromClause->getAlias() !== $alias) {
                continue;
            }

            $fromClause = new Expr\From($fromClause->getFrom(), $fromClause->getAlias(), $indexBy);
        }

        return $this;
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->join('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @param string      $join          The relationship to join.
     * @param string      $alias         The alias of the join.
     * @param string|null $conditionType The condition type constant. Either ON or WITH.
     * @param string|null $condition     The condition for the join.
     * @param string|null $indexBy       The index for the join.
     *
     * @return self
     */
    public function join($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        return $this->innerJoin($join, $alias, $conditionType, $condition, $indexBy);
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     *     [php]
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->innerJoin('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     *
     * @param string      $join          The relationship to join.
     * @param string      $alias         The alias of the join.
     * @param string|null $conditionType The condition type constant. Either ON or WITH.
     * @param string|null $condition     The condition for the join.
     * @param string|null $indexBy       The index for the join.
     *
     * @return static
     */
    public function innerJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        $parentAlias = substr($join, 0, strpos($join, '.'));

        $rootAlias = $this->findRootAlias($alias, $parentAlias);

        $join = new Expr\Join(
            Expr\Join::INNER_JOIN,
            $join,
            $alias,
            $conditionType,
            $condition,
            $indexBy
        );

        return $this->add('join', [$rootAlias => $join], true);
    }

    /**
     * Creates and adds a left join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @param string      $join          The relationship to join.
     * @param string      $alias         The alias of the join.
     * @param string|null $conditionType The condition type constant. Either ON or WITH.
     * @param string|null $condition     The condition for the join.
     * @param string|null $indexBy       The index for the join.
     *
     * @return static
     */
    public function leftJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        $parentAlias = substr($join, 0, (int) strpos($join, '.'));

        $rootAlias = $this->findRootAlias($alias, $parentAlias);

        $join = new Expr\Join(
            Expr\Join::LEFT_JOIN,
            $join,
            $alias,
            $conditionType,
            $condition,
            $indexBy
        );

        return $this->add('join', [$rootAlias => $join], true);
    }

    /**
     * Sets a new value for a field in a bulk update query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->update('User', 'u')
     *         ->set('u.password', '?1')
     *         ->where('u.id = ?2');
     * </code>
     *
     * @param string $key   The key/field to set.
     * @param mixed  $value The value, expression, placeholder, etc.
     *
     * @return static
     */
    public function set($key, $value)
    {
        return $this->add('set', new Expr\Comparison($key, Expr\Comparison::EQ, $value), true);
    }

    /**
     * Specifies one or more restrictions to the query result.
     * Replaces any previously specified restrictions, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = ?');
     *
     *     // You can optionally programmatically build and/or expressions
     *     $qb = $em->createQueryBuilder();
     *
     *     $or = $qb->expr()->orX();
     *     $or->add($qb->expr()->eq('u.id', 1));
     *     $or->add($qb->expr()->eq('u.id', 2));
     *
     *     $qb->update('User', 'u')
     *         ->set('u.password', '?')
     *         ->where($or);
     * </code>
     *
     * @param mixed $predicates The restriction predicates.
     *
     * @return static
     */
    public function where($predicates)
    {
        if (! (func_num_args() === 1 && $predicates instanceof Expr\Composite)) {
            $predicates = new Expr\Andx(func_get_args());
        }

        return $this->add('where', $predicates);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * conjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.username LIKE ?')
     *         ->andWhere('u.is_active = 1');
     * </code>
     *
     * @see where()
     *
     * @param mixed $where The query restrictions.
     *
     * @return static
     */
    public function andWhere()
    {
        $args  = func_get_args();
        $where = $this->getDQLPart('where');

        if ($where instanceof Expr\Andx) {
            $where->addMultiple($args);
        } else {
            array_unshift($args, $where);
            $where = new Expr\Andx($args);
        }

        return $this->add('where', $where);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * disjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = 1')
     *         ->orWhere('u.id = 2');
     * </code>
     *
     * @see where()
     *
     * @param mixed $where The WHERE statement.
     *
     * @return static
     */
    public function orWhere()
    {
        $args  = func_get_args();
        $where = $this->getDQLPart('where');

        if ($where instanceof Expr\Orx) {
            $where->addMultiple($args);
        } else {
            array_unshift($args, $where);
            $where = new Expr\Orx($args);
        }

        return $this->add('where', $where);
    }

    /**
     * Specifies a grouping over the results of the query.
     * Replaces any previously specified groupings, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.id');
     * </code>
     *
     * @param string $groupBy The grouping expression.
     *
     * @return static
     */
    public function groupBy($groupBy)
    {
        return $this->add('groupBy', new Expr\GroupBy(func_get_args()));
    }

    /**
     * Adds a grouping expression to the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.lastLogin')
     *         ->addGroupBy('u.createdAt');
     * </code>
     *
     * @param string $groupBy The grouping expression.
     *
     * @return static
     */
    public function addGroupBy($groupBy)
    {
        return $this->add('groupBy', new Expr\GroupBy(func_get_args()), true);
    }

    /**
     * Specifies a restriction over the groups of the query.
     * Replaces any previous having restrictions, if any.
     *
     * @param mixed $having The restriction over the groups.
     *
     * @return static
     */
    public function having($having)
    {
        if (! (func_num_args() === 1 && ($having instanceof Expr\Andx || $having instanceof Expr\Orx))) {
            $having = new Expr\Andx(func_get_args());
        }

        return $this->add('having', $having);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * conjunction with any existing having restrictions.
     *
     * @param mixed $having The restriction to append.
     *
     * @return static
     */
    public function andHaving($having)
    {
        $args   = func_get_args();
        $having = $this->getDQLPart('having');

        if ($having instanceof Expr\Andx) {
            $having->addMultiple($args);
        } else {
            array_unshift($args, $having);
            $having = new Expr\Andx($args);
        }

        return $this->add('having', $having);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * disjunction with any existing having restrictions.
     *
     * @param mixed $having The restriction to add.
     *
     * @return static
     */
    public function orHaving($having)
    {
        $args   = func_get_args();
        $having = $this->getDQLPart('having');

        if ($having instanceof Expr\Orx) {
            $having->addMultiple($args);
        } else {
            array_unshift($args, $having);
            $having = new Expr\Orx($args);
        }

        return $this->add('having', $having);
    }

    /**
     * Specifies an ordering for the query results.
     * Replaces any previously specified orderings, if any.
     *
     * @param string|Expr\OrderBy $sort  The ordering expression.
     * @param string              $order The ordering direction.
     *
     * @return static
     */
    public function orderBy($sort, $order = null)
    {
        $orderBy = $sort instanceof Expr\OrderBy ? $sort : new Expr\OrderBy($sort, $order);

        return $this->add('orderBy', $orderBy);
    }

    /**
     * Adds an ordering to the query results.
     *
     * @param string|Expr\OrderBy $sort  The ordering expression.
     * @param string              $order The ordering direction.
     *
     * @return static
     */
    public function addOrderBy($sort, $order = null)
    {
        $orderBy = $sort instanceof Expr\OrderBy ? $sort : new Expr\OrderBy($sort, $order);

        return $this->add('orderBy', $orderBy, true);
    }

    /**
     * Adds criteria to the query.
     *
     * Adds where expressions with AND operator.
     * Adds orderings.
     * Overrides firstResult and maxResults if they're set.
     *
     * @return static
     *
     * @throws Query\QueryException
     */
    public function addCriteria(Criteria $criteria)
    {
        $allAliases = $this->getAllAliases();
        if (! isset($allAliases[0])) {
            throw new Query\QueryException('No aliases are set before invoking addCriteria().');
        }

        $visitor = new QueryExpressionVisitor($this->getAllAliases());

        $whereExpression = $criteria->getWhereExpression();
        if ($whereExpression) {
            $this->andWhere($visitor->dispatch($whereExpression));
            foreach ($visitor->getParameters() as $parameter) {
                $this->parameters->add($parameter);
            }
        }

        if ($criteria->getOrderings()) {
            foreach ($criteria->getOrderings() as $sort => $order) {
                $hasValidAlias = false;
                foreach ($allAliases as $alias) {
                    if (strpos($sort . '.', $alias . '.') === 0) {
                        $hasValidAlias = true;
                        break;
                    }
                }

                if (! $hasValidAlias) {
                    $sort = $allAliases[0] . '.' . $sort;
                }

                $this->addOrderBy($sort, $order);
            }
        }

        // Overwrite limits only if they was set in criteria
        $firstResult = $criteria->getFirstResult();
        if ($firstResult !== null) {
            $this->setFirstResult($firstResult);
        }

        $maxResults = $criteria->getMaxResults();
        if ($maxResults !== null) {
            $this->setMaxResults($maxResults);
        }

        return $this;
    }

    /**
     * Gets a query part by its name.
     *
     * @param string $queryPartName
     *
     * @return mixed $queryPart
     */
    public function getDQLPart($queryPartName)
    {
        return $this->_dqlParts[$queryPartName];
    }

    /**
     * Gets all query parts.
     *
     * @psalm-return array<string, mixed> $dqlParts
     */
    public function getDQLParts()
    {
        return $this->_dqlParts;
    }

    private function getDQLForDelete(): string
    {
         return 'DELETE'
              . $this->getReducedDQLQueryPart('from', ['pre' => ' ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);
    }

    private function getDQLForUpdate(): string
    {
         return 'UPDATE'
              . $this->getReducedDQLQueryPart('from', ['pre' => ' ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('set', ['pre' => ' SET ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);
    }

    private function getDQLForSelect(): string
    {
        $dql = 'SELECT'
             . ($this->_dqlParts['distinct'] === true ? ' DISTINCT' : '')
             . $this->getReducedDQLQueryPart('select', ['pre' => ' ', 'separator' => ', ']);

        $fromParts   = $this->getDQLPart('from');
        $joinParts   = $this->getDQLPart('join');
        $fromClauses = [];

        // Loop through all FROM clauses
        if (! empty($fromParts)) {
            $dql .= ' FROM ';

            foreach ($fromParts as $from) {
                $fromClause = (string) $from;

                if ($from instanceof Expr\From && isset($joinParts[$from->getAlias()])) {
                    foreach ($joinParts[$from->getAlias()] as $join) {
                        $fromClause .= ' ' . ((string) $join);
                    }
                }

                $fromClauses[] = $fromClause;
            }
        }

        $dql .= implode(', ', $fromClauses)
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('groupBy', ['pre' => ' GROUP BY ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('having', ['pre' => ' HAVING '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);

        return $dql;
    }

    /**
     * @psalm-param array<string, mixed> $options
     */
    private function getReducedDQLQueryPart(string $queryPartName, array $options = []): string
    {
        $queryPart = $this->getDQLPart($queryPartName);

        if (empty($queryPart)) {
            return $options['empty'] ?? '';
        }

        return ($options['pre'] ?? '')
             . (is_array($queryPart) ? implode($options['separator'], $queryPart) : $queryPart)
             . ($options['post'] ?? '');
    }

    /**
     * Resets DQL parts.
     *
     * @param string[]|null $parts
     * @psalm-param list<string>|null $parts
     *
     * @return static
     */
    public function resetDQLParts($parts = null)
    {
        if ($parts === null) {
            $parts = array_keys($this->_dqlParts);
        }

        foreach ($parts as $part) {
            $this->resetDQLPart($part);
        }

        return $this;
    }

    /**
     * Resets single DQL part.
     *
     * @param string $part
     *
     * @return static
     */
    public function resetDQLPart($part)
    {
        $this->_dqlParts[$part] = is_array($this->_dqlParts[$part]) ? [] : null;
        $this->_state           = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets a string representation of this QueryBuilder which corresponds to
     * the final DQL query being constructed.
     *
     * @return string The string representation of this QueryBuilder.
     */
    public function __toString()
    {
        return $this->getDQL();
    }

    /**
     * Deep clones all expression objects in the DQL parts.
     *
     * @return void
     */
    public function __clone()
    {
        foreach ($this->_dqlParts as $part => $elements) {
            if (is_array($this->_dqlParts[$part])) {
                foreach ($this->_dqlParts[$part] as $idx => $element) {
                    if (is_object($element)) {
                        $this->_dqlParts[$part][$idx] = clone $element;
                    }
                }
            } elseif (is_object($elements)) {
                $this->_dqlParts[$part] = clone $elements;
            }
        }

        $parameters = [];

        foreach ($this->parameters as $parameter) {
            $parameters[] = clone $parameter;
        }

        $this->parameters = new ArrayCollection($parameters);
    }
}
