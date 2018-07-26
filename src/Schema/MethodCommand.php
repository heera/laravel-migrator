<?php

namespace Migrator\Schema;

use Migrator\Schema\Exceptions\InverseMethodNotFound;
use Migrator\Schema\Exceptions\MultipleModelsWithSameShortName;
use Migrator\Schema\Migrator\Field;

class MethodCommand extends Command
{
    const BELONGS_TO = 'BelongsTo';
    const HAS_ONE = 'HasOne';
    const HAS_MANY = 'HasMany';
    const BELONGS_TO_MANY = 'BelongsToMany';
    const HAS_MANY_THROUGH = 'HasManyThrough';
    const POLYMORPHIC_MORPH_TO = 'Polymorphic';
    const POLYMORPHIC_MORPH_MANY = 'MorphMany';
    const POLYMORPHIC_MORPH_TO_MANY = 'MorphToMany';
    const POLYMORPHIC_MORPHED_BY_MANY = 'MorphedByMany';

    protected $name;
    private $joinString;
    private $via;
    private $returnType;
    private $inverseOf;
    private $as;
    private $pivotWithTimestamps = false;
    private $isNullable = false;

    // from `increments()`
    public static $defaultPivotFieldType = 'unsignedInteger';

    public function __construct($name)
    {
        $this->name = $name;
    }

    public static function fromString($line, ModelCommand $model)
    {
        $line = ltrim($line);
        $line = preg_replace('#,\s*\r?\n\s+#', ', ', $line);

        // tags() via Taggable.taggable(): Tag[] <- Tag.videos(),
        //    Join(Table1.field1 = Table2.field2 AND Table3.field3 = Table4.field4),
        //    As("subscription"),
        //    PivotWithTimestamps

        // phone(): Phone  Join(User.phone_id = Phone.id)

        $returnTypeRx = '(?P<return_type>[A-Za-z0-9\|]+(\[\])?)';

        $viaNameRx = "(?P<via>[a-zA-Z0-9_\.\(\)]+)";
        $viaRx = "(\s*via\s*$viaNameRx)?";
        $nameRx = "((?P<name>[A-Za-z0-9_]+)\(\)$viaRx)";
        $tagsRx = "(?P<tags>(.*))";

        $regex = "#^{$nameRx}(:\s+$returnTypeRx(\s+$tagsRx)?)?\s*$#";

        if (!preg_match($regex, $line, $m)) {
            return null;
        }

        $d = new self($m['name']);
        $d->setModel($model);
        $d->setVia(array_get($m, 'via'));
        $d->setReturnType(array_get($m, 'return_type'));

        $d->parseTags(array_get($m, 'tags'));


        return $d;
    }

    private function parseTags($tagString)
    {
        if (empty($tagString)) {
            return;
        }

        $joinStringRx = '/^(?P<join>Join\((?P<join_string>.*?)\))$/';
        $inverseOfRx = '/^<-\s+(?P<inverse_of>[A-Za-z0-9\._]+\(\))$/';

        $asDoubleQuotesRx = '"(?P<as1>[^"]+)"';
        $asSingleQuoteRx = "'(?P<as2>[^']+)'";
        $asFieldRx = "({$asDoubleQuotesRx}|{$asSingleQuoteRx})";
        $asRx = "/^As\\({$asFieldRx}\\)\$/";
        $notNullRx = '/^NotNull$/';
        $nullRx = '/^(Null|Nullable)$/';

        $pivotWithTimestampsRx = "/^PivotWithTimestamps\$/i";

        $tags = preg_split('#,\s*#', $tagString); // TODO: probably too naive
        foreach ($tags as $tag) {
            if (preg_match($joinStringRx, $tag, $m1)) {
                $this->setJoinString(array_get($m1, 'join_string'));
                $this->parseJoin();
            } elseif (preg_match($inverseOfRx, $tag, $m1)) {
                $this->setInverseOf(array_get($m1, 'inverse_of'));
            } elseif (preg_match($asRx, $tag, $m1)) {
                $this->setAs(array_get($m1, 'as1', '') . array_get($m1, 'as2', ''));
            } elseif (preg_match($pivotWithTimestampsRx, $tag, $m1)) {
                $this->pivotWithTimestamps = true;
            } elseif (preg_match($notNullRx, $tag, $m1)) {
                $this->isNullable = false;
            } elseif (preg_match($nullRx, $tag, $m1)) {
                $this->isNullable = true;
            } else {
                throw new \RuntimeException("Cannot parse tag for method \"{$this->modelShortName()}.{$this->name}()\": '$tag'");
            }
        }
    }

    /**
     * @return mixed
     */
    private function parseJoin()
    {
        if (empty($this->getJoinString())) {
            return [];
        }

        $name = '[A-Za-z0-9_]+';

        $condition1 = "(?P<cond1_l_table>$name)\\.(?P<cond1_l_field>$name)\\s*=" .
            "\\s*(?P<cond1_r_table>$name)\\.(?P<cond1_r_field>$name)";

        $condition2 = "(?P<cond2_l_table>$name)\\.(?P<cond2_l_field>$name)\\s*=" .
            "\\s*(?P<cond2_r_table>$name)\\.(?P<cond2_r_field>$name)";

        $joinRegex = "/^\\s*$condition1((\\s+AND\\s+$condition2)\\s*)?\$/";

        if (!preg_match($joinRegex, $this->getJoinString(), $match)) {
            throw new \RuntimeException("Cannot parse join: " . $this->getJoinString());
        }

        $results = array_except($match, range(0, 20)); // remove non-named matches

        if (isset($results['cond2_l_table'])) {
            $tables = [
                $results['cond1_l_table'],
                $results['cond1_r_table'],
                $results['cond2_l_table'],
                $results['cond2_r_table'],
            ];
            $number = array_values(array_count_values($tables));
            sort($number);
            if ($number != [1, 1, 2]) {
                throw new \RuntimeException("Joins with \"AND\" are currently used only for many-to-many relations " .
                    "and so should reference the same table twice, like this: " .
                    "\"table1.X = table2.Y AND table2.Z = table3.A\" " .
                    "see: \"{$this->getJoinString()}\"");
            }
        }

        return $results;
    }

    /**
     * @return string
     */
    private function modelShortName()
    {
        return $this->getModel()->getShortName();
    }

    /**
     * @return mixed
     */
    public function getAs()
    {
        return $this->as;
    }

    /**
     * @param mixed $as
     */
    public function setAs($as): void
    {
        $this->as = $as;
    }

    public function getCommandType()
    {
        return 'Method';
    }

    public function getJoinString()
    {
        try {
            if (empty($this->joinString) && $this->inverseMethod()->joinString) {
                return $this->inverseMethod()->joinString;
            }
        } catch (\Exception $e) {
            // it's ok, we tried..
        }
        return $this->joinString;
    }

    /**
     * @param mixed $joinString
     */
    public function setJoinString($joinString): void
    {
        $this->joinString = $joinString;
    }

    public function isPivotWithTimestamps()
    {
        return $this->pivotWithTimestamps;
    }

    public function isBelongsTo()
    {
        if (!$this->returnsOne()) {
            return false;
        }
        return $this->relationType() == self::BELONGS_TO;
    }

    public function relationType()
    {
        if ($this->isReturnTypePolymorphic()) {
            return self::POLYMORPHIC_MORPH_TO;
        }

        if ($this->hasPivotJoin()) {
            return self::BELONGS_TO_MANY;
        }

        if ($this->returnsOne()) {
            if ($this->getModel()->hasField($this->belongsToFieldName())) {
                return self::BELONGS_TO;
            }

            if ($this->viaOrJoinCreatesField()) {
                return self::BELONGS_TO;
            }

            try {
                $this->inverseMethod();
            } catch (InverseMethodNotFound $e) {
                $this->throwReturnsOneThingAskToAddVia();
            }

            if ($this->inverseMethodCreatesFieldInOurTable()) {
                return self::BELONGS_TO;
            }

            $inverseMethod = $this->inverseMethod();
            if ($inverseMethod->viaOrJoinCreatesField() || $inverseMethod->belongsToFieldExists()) {
                return self::HAS_ONE;
            }

            if ($inverseMethod->returnsMany()) {
                return self::BELONGS_TO;
            }

            if (!$inverseMethod->returnsMany()) {
                $this->throwOneToOneConfusing();
            }
        }

        if ($this->returnsMany()) {
            $inverseMethod = $this->inverseMethod();


            if ($inverseMethod->returnsOne()) {
                if ($this->getVia() && $viaModel = $this->getSchema()->getModel($this->getVia())) {
                    // morphToMany is like Post.tags() via Taggable
                    if ($inverseMethod->isPolymorphic()) {
                        return self::POLYMORPHIC_MORPH_TO_MANY;
                    }

                    // morphedByMany is like Tag.posts() via Taggable
                    // find the method that reutrns what we want in the via model
                    $methods = $viaModel->findMethodsByReturnType($this->getReturnTypeSingle());
                    if (count($methods) == 1 && $methods[0]->isPolymorphic()) {
                        return self::POLYMORPHIC_MORPHED_BY_MANY;
                    }

                    return self::HAS_MANY_THROUGH;
                }

                // isMorphMany returns many and inverse method is polymorphic and can return our type
                if ($inverseMethod->isPolymorphic()) {
                    return self::POLYMORPHIC_MORPH_MANY;
                }
                return self::HAS_MANY;
            }

            if ($inverseMethod->returnsMany()) {
                return self::BELONGS_TO_MANY;
            }
        }
    }

    /**
     * @return bool
     */
    private function isReturnTypePolymorphic(): bool
    {
        return str_contains($this->getReturnType(), '|');
    }

    private function hasPivotJoin()
    {
        return str_contains($this->getJoinString(), ' AND ');
    }

    /**
     * @return bool
     */
    public function returnsOne(): bool
    {
        return !$this->returnsMany();
    }

    /**
     * @return string
     * @throws MultipleModelsWithSameShortName
     */
    public function belongsToFieldName(): string
    {
        if ($this->viaCreatesField()) {
            return $this->getVia();
        }

        try {
            if ($field = $this->inverseMethodCreatesFieldInOurTable()) {
                return $field;
            }
        } catch (InverseMethodNotFound $e) {
            // skip, if there is not other method - then we can do nothing here
        }

        return str_singular($this->name) . "_id";
    }

    public function isBelongsFieldDefault()
    {
        return $this->belongsToFieldName() === $this->name . "_id";
    }

    private function viaOrJoinCreatesField()
    {
        return $this->viaCreatesField() || $this->joinCreatesField();
    }

    /**
     * @return null|MethodCommand
     * @throws InverseMethodNotFound
     * @throws MultipleModelsWithSameShortName
     */
    public function inverseMethod()
    {
        $otherModel = $this->guessInverseModelName();
        $otherMethodSingular = str_singular(snake_case($this->modelShortName()));
        $otherMethodPlural = str_plural(snake_case($this->modelShortName()));


        if ($this->getInverseOf()) {
            return $this->getInverseOfMethod();
        } else {
            $model = $this->getSchema()->getModel($otherModel);
        }

//        if($this->viaOrJoinCreatesField()) {
//            $model = $this->getModel();
//        }

        if ($model) {
            $hint = "we tried to look for methods `{$model->getShortName()}.{$otherMethodSingular}()` and " .
                "`{$model->getShortName()}.{$otherMethodPlural}()`, but neither was there.";
            try {
                return $model->getMethod($otherMethodPlural);
            } catch (\Exception $e) {
            }

            try {
                return $model->getMethod($otherMethodSingular);
            } catch (\Exception $e) {
            }
        } else {
            $hint = "we tried to guess it was in the model `{$otherModel}`, but the model was not found";
        }

        $message = "Cannot deduce inverse method for `{$this->modelShortName()}.{$this->name}()`, $hint";

        if ($method = $this->guessInverseMethodByReturnTypeEqualsOurModel()) {
            return $method;
        }

        throw new InverseMethodNotFound($message);
    }

    private function throwReturnsOneThingAskToAddVia(): void
    {
        $field = $this->belongsToFieldName();

        $otherMethod = str_plural(snake_case($this->modelShortName()));
        $otherModel = studly_case($this->name);

        $message = "Method `{$this->humanName()}` returns only one thing, so it is probably of type " .
            "`Belongs To`, which requires field `$field`, but I'm not sure if I should create it " .
            "(fix: define method `$otherModel.$otherMethod()` " .
            "or define field in model `{$this->modelShortName()}` as `$this->name() via $field` " .
            "or as `$field: integer` field)";

        throw new \RuntimeException($message);
    }

    /**
     * @return null
     * @throws InverseMethodNotFound
     * @throws MultipleModelsWithSameShortName
     */
    private function inverseMethodCreatesFieldInOurTable()
    {
        return $this->inverseMethod()->fieldThatJoinCreatesIn($this->modelShortName());
    }

    public function returnsMany()
    {
        if ($this->returnType) {
            return ends_with($this->getReturnType(), '[]');
        }

        return $this->name == str_plural($this->name);
    }

    private function throwOneToOneConfusing(): void
    {
        $This = $this->modelShortName();
        $That = $this->inverseMethod()->getModel()->getShortName();
        $thisLower = strtolower($That);
        $thatLower = strtolower($This);
        $message = "Model $This contains a confusing One to One definition between `$This.$thisLower()` and `$That.$thatLower()`. " .
            "One to one requires a field in one of these tables. " .
            "To resolve it: if $This (usually) belongs to $That - then add `$thisLower() via {$thisLower}_id` to $This; " .
            "otherwise if $That (usually) belongs to $This - then add `{$thatLower}() via {$thatLower}_id` to $That.";
        throw new \RuntimeException($message);
    }

    /**
     * @return mixed
     */
    public function getVia()
    {
        return $this->via;
    }

    /**
     * @param mixed $via
     */
    public function setVia($via): void
    {
        $this->via = $via;
    }

    public function isPolymorphic()
    {
        return $this->isReturnTypePolymorphic();
    }

    public function getReturnTypeSingle()
    {
        return preg_replace('#\[\]$#', '', $this->getReturnType());
    }

    /**
     * @return mixed
     */
    public function getReturnType()
    {
        if (!$this->returnType) {
            if ($this->name == str_plural($this->name)) {
                return studly_case(str_singular($this->name)) . '[]';
            } else {
                return studly_case($this->name);
            }
        }

        return $this->returnType;
    }

    public function getPolymorphicReturnTypeMatching($name)
    {
        $types = $this->getPolymorphicReturnTypes();
        if (in_array($name, $types)) {
            return $name;
        }
        return null;
    }

    /**
     * @param mixed $returnType
     */
    public function setReturnType($returnType): void
    {
        $old = $this->returnType;
        $this->returnType = $returnType;
        if ($this->isReturnTypePolymorphic() && $this->returnsMany()) {
            $this->returnType = $old;

            $did = ".";
            if (ends_with($returnType, '[]')) {
                $without = preg_replace('#\[\]$#', '', $returnType);
                $did = ", did you mean just `$without` instead of `{$returnType}`?";
            }

            throw new \RuntimeException("Your `{$this->humanName()}` method asks for polymorphic relation with array, I am not sure how to do it$did");
        }
    }

    public function viaCreatesField()
    {
        if (empty($this->via)) {
            return false;
        }
        // has many through: posts via User
        if ($this->getSchema()->getModel($this->via)) {
            return false;
        }
        return !ends_with($this->via, '()');
    }

    public function joinCreatesField()
    {
        if (empty($this->getJoinString())) {
            return false;
        }

        return $this->findOwnFieldInJoinExceptPrimaryKey() !== null;
    }

    /**
     * @return string
     */
    private function guessInverseModelName(): string
    {
        if ($this->getVia() && $model = $this->getSchema()->getModel($this->getVia())) {
            return $this->getVia();
        }

        if ($this->getSchema()->getModel($this->getReturnTypeSingle())) {
            return $this->getReturnTypeSingle();
        }

        return str_singular(studly_case($this->name));
    }

    /**
     * @return mixed
     */
    public function getInverseOf()
    {
        return $this->inverseOf;
    }

    /**
     * @param mixed $inverseOf
     */
    public function setInverseOf($inverseOf): void
    {
        $this->inverseOf = $inverseOf;
    }

    /**
     * @return MethodCommand
     * @throws MultipleModelsWithSameShortName
     */
    private function getInverseOfMethod(): MethodCommand
    {
        $model = $this->getSchema()->getModel($this->getInverseOfModelName());
        return $model->getMethod($this->getInverseOfMethodName());
    }

    private function guessInverseMethodByReturnTypeEqualsOurModel()
    {
        $model = $this->getSchema()->getModel($this->guessInverseModelName());

        if (!$model) {
            return null;
        }

        if ($methods = $model->findMethodsByReturnType($this->modelShortName())) {
            if (count($methods) == 1) {
                return $methods[0];
            }
        }

        if ($methods = $model->findMethodsByReturnType($this->modelShortName() . '[]')) {
            if (count($methods) == 1) {
                return $methods[0];
            }
        }

        return null;
    }

    public function humanName()
    {
        $model = $this->modelShortName();
        $name = $this->name;
        return "$model.$name()";
    }

    public function fieldThatJoinCreatesIn($modelShortName)
    {
        if (empty($this->getJoinString())) {
            return null;
        }

        $p = $this->parseJoin();
        if ($p['cond1_l_table'] == $modelShortName && $p['cond1_l_field'] !== 'id') {
            return $p['cond1_l_field'];
        }

        if ($p['cond1_r_table'] == $modelShortName && $p['cond1_r_field'] !== 'id') {
            return $p['cond1_r_field'];
        }

        return null;
    }

    public function findOwnFieldInJoinExceptPrimaryKey()
    {
        $field = $this->findOwnFieldInJoin();
        if ($field && !in_array($field, $this->getModel()->getPrimaryKeyFieldNames())) {
            return $field;
        }
        return null;
    }

    public function findOwnFieldInJoin()
    {
        $p = $this->parseJoin();
        if (empty($p)) {
            return null;
        }

        if ($p['cond1_l_table'] == $this->getModel()->getShortName()) {
            return $p['cond1_l_field'];
        }
        if ($p['cond1_r_table'] == $this->getModel()->getShortName()) {
            return $p['cond1_r_field'];
        }

        return null;
    }

    private function getInverseOfModelName()
    {
        $p = $this->parseInverseOfIntoParts();
        return $p[0];
    }

    private function getInverseOfMethodName()
    {
        $p = $this->parseInverseOfIntoParts();
        return $p[1];
    }

    public function getName()
    {
        return $this->name;
    }

    private function parseInverseOfIntoParts(): array
    {
        $l = preg_replace('/\(\)/', '', $this->getInverseOf());
        $p = explode(".", $l);
        if (count($p) != 2) {
            throw new \RuntimeException("Inverse of (<-) should be in format `Model.method()`");
        }
        return $p;
    }

    public function isMorphMany()
    {
        return $this->relationType() == self::POLYMORPHIC_MORPH_MANY;
    }

    public function isMorphToMany()
    {
        return $this->relationType() == self::POLYMORPHIC_MORPH_TO_MANY;
    }

    public function isMorphedByMany()
    {
        return $this->relationType() == self::POLYMORPHIC_MORPHED_BY_MANY;
    }

    /**
     * @return MethodCommand
     * @throws MultipleModelsWithSameShortName
     */
    public function hasManyThroughIntermediateMethod()
    {
        if ($this->getVia() && $this->getSchema()->getModel($this->getVia())) {
            $otherModel = $this->getSchema()->getModel($this->getVia());
            $methods = $otherModel->findMethodsByReturnType($this->getReturnType());
            if (count($methods) == 1) {
                return $methods[0];
            }
            if (count($methods) == 0) {
                throw new \RuntimeException("There is no method returning `{$this->modelShortName()}` in `{$otherModel->getShortName()}`");
            }
        }
    }

    public function isHasOne()
    {
        return $this->relationType() == self::HAS_ONE;
    }

    public function isHasMany()
    {
        return $this->relationType() == self::HAS_MANY;
    }

    public function isHasManyThrough()
    {
        return $this->relationType() == self::HAS_MANY_THROUGH;
    }

    public function isManyToMany()
    {
        return $this->isBelongsToMany();
    }

    /**
     * Returns true if this is the "first" (alphabetically) of two tables, like in "users" and "roles",
     * "roles" would be first.
     *
     * @return bool
     * @throws InverseMethodNotFound
     * @throws MultipleModelsWithSameShortName
     */
    public function isManyToManyFirst()
    {
        return strcmp($this->modelShortName(), $this->inverseMethod()->modelShortName()) < 0;
    }

    public function isBelongsToMany()
    {
        return $this->relationType() == self::BELONGS_TO_MANY;
    }

    public function getPivotTableName($returnNullIfDefault = false)
    {
        if ($this->hasPivotJoin()) {
            return $this->getPivotTableNameFromJoin();
        }

        if ($this->inverseMethod()->hasPivotJoin()) {
            return $this->inverseMethod()->getPivotTableNameFromJoin();
        }

        if ($returnNullIfDefault) {
            return null;
        }

        $table1 = str_singular($this->getModel()->getTableName());
        $table2 = str_singular($this->inverseMethod()->getModel()->getTableName());

        return collect([$table1, $table2])->sort()->implode('_');
    }

    private function getPivotTableNameFromJoin()
    {
        $m = $this->parseJoin();

        $table = $m['cond1_r_table'];
        if ($model = $this->getSchema()->getModel($table)) {
            return $model->getTableName();
        }
        return $table;
    }

    public function canReturn($modelShortName)
    {
        if ($this->getReturnType() == $modelShortName) {
            return true;
        }
        if ($this->isPolymorphic()) {
            return in_array($modelShortName, $this->getPolymorphicReturnTypes());
        }

        return false;
    }

    private function getPolymorphicReturnTypes()
    {
        return explode('|', $this->getReturnType());
    }

    /**
     * @return string
     * @throws InverseMethodNotFound
     * @throws MultipleModelsWithSameShortName
     */
    public function laravelRelationCall()
    {
        return (new LaravelRelationMethodCallBuilder($this))->build();
    }

    /**
     * @return bool
     * @throws MultipleModelsWithSameShortName
     */
    private function belongsToFieldExists()
    {
        $bf = $this->belongsToFieldName();
        return $this->getModel()->hasField($bf);
    }

    public function ourKeyInPivot($nullIfDefault = false)
    {
        if ($this->hasPivotJoin()) {
            return $this->findOwnFieldsOtherSideInJoin();
        }

        if ($nullIfDefault) {
            return null;
        }

        return str_singular($this->name) . '_id';
    }

    public function getPivotTableDefinitionFields()
    {
        return [
            $this->getPivotTableDefinitionOurField(),
            $this->inverseMethod()->getPivotTableDefinitionOurField(),
        ];
    }

    /**
     * @return Field
     */
    private function getPivotTableDefinitionOurField(): Field
    {
        $nullable = $this->isNullable !== null ? $this->isNullable : $this->getSchema()->getDefaultIsNullable();
        $field = new Field(
            $this->getModel()->getTableName(),
            $this->ourKeyInPivot(),
            self::$defaultPivotFieldType,
            $nullable
        );
        return $field;
    }

    private function findOwnFieldsOtherSideInJoin()
    {
        $modelOrTable = $this->modelShortName();
        $p = $this->parseJoinToPairsForModelOrTable($modelOrTable);
        if (count($p) == 0) {
            throw new \RuntimeException("Trying to parse '{$this->getJoinString()}' I needed to find part that matches table or model $modelOrTable, but couldn't");
        }
        return $p[0]['field2'];
    }

    private function parseJoinToPairs()
    {
        $p = $this->parseJoin();
        return [
            [
                'table1' => $p['cond1_l_table'],
                'field1' => $p['cond1_l_field'],
                'table2' => $p['cond1_r_table'],
                'field2' => $p['cond1_r_field']
            ],
            [
                'table1' => $p['cond1_r_table'],
                'field1' => $p['cond1_r_field'],
                'table2' => $p['cond1_l_table'],
                'field2' => $p['cond1_l_field']
            ],
            [
                'table1' => $p['cond2_l_table'],
                'field1' => $p['cond2_l_field'],
                'table2' => $p['cond2_r_table'],
                'field2' => $p['cond2_r_field']
            ],
            [
                'table1' => $p['cond2_r_table'],
                'field1' => $p['cond2_r_field'],
                'table2' => $p['cond2_l_table'],
                'field2' => $p['cond2_l_field']
            ],
        ];
    }

    private function parseJoinToPairsForModelOrTable($modelOrTable)
    {
        $arr = [$modelOrTable];
        if ($model = $this->getSchema()->getModel($modelOrTable)) {
            $arr [] = $model->getTableName();
        }
        if ($model = $this->getSchema()->getModelByTableName($modelOrTable)) {
            $arr [] = $model->getShortName();
        }
        $p = collect($this->parseJoinToPairs());
        return $p->whereIn('table1', $arr)->values()->all();
    }

    public function explicitManyToManyModel()
    {
        return $this->getSchema()->getModelByTableName($this->getPivotTableName());
    }
}
