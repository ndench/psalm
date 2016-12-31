<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Exception\DocblockParseException;
use Psalm\Issue\DeprecatedMethod;
use Psalm\Issue\InaccessibleMethod;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidStaticInvocation;
use Psalm\Issue\UndefinedMethod;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\MethodStorage;
use Psalm\Type;

class MethodChecker extends FunctionLikeChecker
{
    const VISIBILITY_PUBLIC = 1;
    const VISIBILITY_PROTECTED = 2;
    const VISIBILITY_PRIVATE = 3;

    /**
     * @param PhpParser\Node\FunctionLike $function
     * @param StatementsSource            $source
     * @param array                       $this_vars
     * @psalm-suppress MixedAssignment
     */
    public function __construct($function, StatementsSource $source, array $this_vars = [])
    {
        if (!$function instanceof PhpParser\Node\Stmt\ClassMethod) {
            throw new \InvalidArgumentException('Must be called with a ClassMethod');
        }

        parent::__construct($function, $source);

        $this->registerMethod($function);
        $this->is_static = $function->isStatic();
    }

    /**
     * @param  string $method_id
     * @return array<int, \Psalm\FunctionLikeParameter>|null
     */
    public static function getMethodParams($method_id)
    {
        self::registerClassMethod($method_id);

        if ($method_id = self::getDeclaringMethodId($method_id)) {
            $storage = self::getStorage($method_id);

            if ($storage) {
                return $storage->params;
            }
        }
    }

    /**
     * @param  string $method_id
     * @return boolean
     */
    public static function isVariadic($method_id)
    {
        self::registerClassMethod($method_id);

        $method_id = (string)self::getDeclaringMethodId($method_id);

        list($fq_class_name, $method_name) = explode('::', $method_id);

        return ClassLikeChecker::$storage[$fq_class_name]->methods[$method_name]->variadic;
    }

    /**
     * @param  string $method_id
     * @return Type\Union|null
     */
    public static function getMethodReturnType($method_id)
    {
        /** @var string */
        $method_id = self::getDeclaringMethodId($method_id);

        list($fq_class_name, $method_name) = explode('::', $method_id);

        if (!ClassLikeChecker::isUserDefined($fq_class_name) && FunctionChecker::inCallMap($method_id)) {
            return FunctionChecker::getReturnTypeFromCallMap($method_id);
        }

        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        if ($storage->return_type) {
            return clone $storage->return_type;
        }

        $class_storage = ClassLikeChecker::$storage[$fq_class_name];

        foreach ($class_storage->overridden_method_ids[$method_name] as $overridden_method_id) {
            $overridden_storage = self::getStorage($overridden_method_id);

            if ($overridden_storage && $overridden_storage->return_type) {
                if ($overridden_storage->return_type->isNull()) {
                    return Type::getVoid();
                }

                return clone $overridden_storage->return_type;
            }
        }

        return null;
    }

    /**
     * @param  string               $method_id
     * @param  CodeLocation|null    $defined_location
     * @return CodeLocation|null
     */
    public static function getMethodReturnTypeLocation($method_id, CodeLocation &$defined_location = null)
    {
        self::registerClassMethod($method_id);

        /** @var string */
        $method_id = self::getDeclaringMethodId($method_id);

        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        if (!$storage->return_type_location) {
            $overridden_method_ids = self::getOverriddenMethodIds($method_id);

            foreach ($overridden_method_ids as $overridden_method_id) {
                $overridden_storage = self::getStorage($overridden_method_id);

                if ($overridden_storage && $overridden_storage->return_type_location) {
                    $defined_location = $overridden_storage->return_type_location;
                    break;
                }
            }
        }

        return $storage->return_type_location;
    }

    /**
     * @param \ReflectionMethod $method
     * @return null
     */
    public static function extractReflectionMethodInfo(\ReflectionMethod $method)
    {
        $method_name = strtolower($method->getName());

        $class_storage = ClassLikeChecker::$storage[$method->class];
        $storage = $class_storage->methods[strtolower($method_name)] = new MethodStorage();

        $storage->cased_name = $method->name;

        if (strtolower((string)$method->name) === strtolower((string)$method->class)) {
            self::setDeclaringMethodId($method->class . '::__construct', $method->class . '::' . $method_name);
        }

        if ($storage->reflected) {
            return null;
        }

        /** @var \ReflectionClass */
        $declaring_class = $method->getDeclaringClass();

        $storage->reflected = true;

        $storage->is_static = $method->isStatic();
        $storage->file_name = $method->getFileName();
        $storage->namespace = $declaring_class->getNamespaceName();
        $class_storage->declaring_method_ids[$method_name] =
            $declaring_class->name . '::' . strtolower((string)$method->getName());
        $class_storage->overridden_method_ids[$method_name] = [];

        $storage->visibility = $method->isPrivate()
            ? self::VISIBILITY_PRIVATE
            : ($method->isProtected() ? self::VISIBILITY_PROTECTED : self::VISIBILITY_PUBLIC);

        $params = $method->getParameters();

        $method_param_names = [];
        $method_param_types = [];

        $storage->params = [];

        /** @var \ReflectionParameter $param */
        foreach ($params as $param) {
            $param_array = self::getReflectionParamArray($param);
            $storage->params[] = $param_array;
            $method_param_names[$param->name] = true;
            $method_param_types[$param->name] = $param_array->type;
        }

        $return_types = null;

        $config = Config::getInstance();

        $return_type = null;

        $storage->return_type = $return_type;
        return null;
    }

    /**
     * Determines whether a given method is static or not
     *
     * @param  string          $method_id
     * @param  CodeLocation    $code_location
     * @param  array<string>   $suppressed_issues
     * @return bool
     */
    public static function checkMethodStatic($method_id, CodeLocation $code_location, array $suppressed_issues)
    {
        self::registerClassMethod($method_id);

        /** @var string */
        $method_id = self::getDeclaringMethodId($method_id);

        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        if (!$storage->is_static) {
            if (IssueBuffer::accepts(
                new InvalidStaticInvocation(
                    'Method ' . MethodChecker::getCasedMethodId($method_id) . ' is not static',
                    $code_location
                ),
                $suppressed_issues
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PhpParser\Node\Stmt\ClassMethod $method
     * @return null|false
     * @psalm-suppress MixedAssignment
     */
    protected function registerMethod(PhpParser\Node\Stmt\ClassMethod $method)
    {
        $method_id = $this->fq_class_name . '::' . strtolower($method->name);

        $class_storage = ClassLikeChecker::$storage[$this->fq_class_name];
        $storage = $class_storage->methods[strtolower($method->name)] = new MethodStorage();

        $cased_method_id = $storage->cased_name = $method->name;

        if (strtolower((string)$method->name) === strtolower((string)$this->class_name)) {
            self::setDeclaringMethodId($this->fq_class_name . '::__construct', $method_id);
        }

        if ($storage->reflected || $storage->registered) {
            $this->suppressed_issues = $storage->suppressed_issues;

            return null;
        }

        if (!$this->source instanceof TraitChecker) {
            $storage->registered = true;
        }

        $class_storage->declaring_method_ids[strtolower($method->name)] = $method_id;

        if (!isset($class_storage->overridden_method_ids[strtolower($method->name)])) {
            $class_storage->overridden_method_ids[strtolower($method->name)] = [];
        }

        $storage->is_static = $method->isStatic();

        $storage->namespace = $this->namespace;
        $storage->file_name = $this->file_name;

        if ($method->isPrivate()) {
            $storage->visibility = self::VISIBILITY_PRIVATE;
        } elseif ($method->isProtected()) {
            $storage->visibility = self::VISIBILITY_PROTECTED;
        } else {
            $storage->visibility = self::VISIBILITY_PUBLIC;
        }

        $method_param_names = [];

        foreach ($method->getParams() as $param) {
            $param_array = $this->getTranslatedParam(
                $param,
                $this
            );

            $storage->params[] = $param_array;
            $method_param_names[$param->name] = $param_array->type;
        }

        $config = Config::getInstance();
        $return_type = null;
        $return_type_location = null;

        $doc_comment = $method->getDocComment();

        $storage->suppressed_issues = [];

        if (isset($method->returnType)) {
            $parser_return_type = $method->returnType;

            $suffix = '';

            if ($parser_return_type instanceof PhpParser\Node\NullableType) {
                $suffix = '|null';
                $parser_return_type = $parser_return_type->type;
            }

            $return_type = Type::parseString(
                (is_string($parser_return_type)
                    ? $parser_return_type
                    : ClassLikeChecker::getFQCLNFromNameObject(
                        $parser_return_type,
                        $this->namespace,
                        $this->getAliasedClasses()
                    )
                ) . $suffix
            );

            $return_type_location = new CodeLocation($this->getSource(), $method, false, self::RETURN_TYPE_REGEX);
        }

        if ($doc_comment) {
            $docblock_info = null;

            try {
                $docblock_info = CommentChecker::extractDocblockInfo((string)$doc_comment, $doc_comment->getLine());
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        'Invalid type passed in docblock for ' . $cased_method_id,
                        new CodeLocation($this, $method)
                    )
                )) {
                    return false;
                }
            }

            if ($docblock_info) {
                if ($docblock_info->deprecated) {
                    $storage->deprecated = true;
                }

                if ($docblock_info->variadic) {
                    $storage->variadic = true;
                }

                $this->suppressed_issues = $docblock_info->suppress;
                $storage->suppressed_issues = $this->suppressed_issues;

                if ($config->use_docblock_types) {
                    if ($docblock_info->return_type) {
                        $docblock_return_type = Type::parseString(
                            $this->fixUpLocalType(
                                (string)$docblock_info->return_type,
                                $this->fq_class_name,
                                $this->namespace,
                                $this->getAliasedClasses()
                            )
                        );

                        if (!$return_type_location) {
                            $return_type_location = new CodeLocation($this->getSource(), $method, true);
                        }

                        if ($return_type && !TypeChecker::hasIdenticalTypes($return_type, $docblock_return_type)) {
                            if (IssueBuffer::accepts(
                                new InvalidDocblock(
                                    'Docblock return type does not match method return type for ' . $this->getMethodId(),
                                    new CodeLocation($this, $method, true)
                                )
                            )) {
                                return false;
                            }
                        } else {
                            $return_type = $docblock_return_type;
                        }

                        $return_type_location->setCommentLine($docblock_info->return_type_line_number);
                    }

                    if ($docblock_info->params) {
                        $this->improveParamsFromDocblock(
                            $docblock_info->params,
                            $method_param_names,
                            $storage->params,
                            new CodeLocation($this, $method, true)
                        );
                    }
                }
            }
        }

        $storage->return_type_location = $return_type_location;
        $storage->return_type = $return_type;
        return null;
    }

    /**
     * @param  string $return_type
     * @param  string $method_id
     * @return string
     */
    protected static function fixUpReturnType($return_type, $method_id)
    {
        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        if (strpos($return_type, '[') !== false) {
            $return_type = Type::convertSquareBrackets($return_type);
        }

        $return_type_tokens = Type::tokenize($return_type);

        foreach ($return_type_tokens as $i => &$return_type_token) {
            if ($return_type_token[0] === '\\') {
                $return_type_token = substr($return_type_token, 1);
                continue;
            }

            if (in_array($return_type_token, ['<', '>', '|', '?', ',', '{', '}', ':'])) {
                continue;
            }

            if (isset($return_type_token[$i + 1]) && $return_type_token[$i + 1] === ':') {
                continue;
            }

            $return_type_token = Type::fixScalarTerms($return_type_token);

            if ($return_type_token[0] === strtoupper($return_type_token[0])) {
                $fq_class_name = explode('::', $method_id)[0];

                if ($return_type_token === '$this') {
                    $return_type_token = $fq_class_name;
                    continue;
                }

                $return_type_token = FileChecker::getFQCLNFromNameInFile(
                    $return_type_token,
                    $storage->namespace,
                    $storage->file_name
                );
            }
        }

        return implode('', $return_type_tokens);
    }

    /**
     * @param  string       $method_id
     * @param  CodeLocation $code_location
     * @param  array        $suppressed_issues
     * @return bool|null
     */
    public static function checkMethodExists($method_id, CodeLocation $code_location, array $suppressed_issues)
    {
        if (self::methodExists($method_id)) {
            return true;
        }

        if (IssueBuffer::accepts(
            new UndefinedMethod('Method ' . $method_id . ' does not exist', $code_location),
            $suppressed_issues
        )) {
            return false;
        }

        return null;
    }

    /**
     * Whether or not a given method exists
     *
     * @param  string $method_id
     * @return bool
     */
    public static function methodExists($method_id)
    {
        // remove trailing backslash if it exists
        $method_id = preg_replace('/^\\\\/', '', $method_id);
        $method_parts = explode('::', $method_id);
        $method_parts[1] = strtolower($method_parts[1]);
        $method_id = implode('::', $method_parts);

        $old_method_id = null;

        ClassLikeChecker::registerClass($method_parts[0]);

        $class_storage = ClassLikeChecker::$storage[$method_parts[0]];

        if (isset($class_storage->declaring_method_ids[$method_parts[1]])) {
            return true;
        }

        // support checking oldstyle constructors
        if ($method_parts[1] === '__construct') {
            $method_part_parts = explode('\\', $method_parts[0]);
            $old_constructor_name = array_pop($method_part_parts);
            $old_method_id = $method_parts[0] . '::' . $old_constructor_name;
        }

        if (FunctionChecker::inCallMap($method_id) || ($old_method_id && FunctionChecker::inCallMap($method_id))) {
            return true;
        }

        return false;
    }

    /**
     * @param  string $method_id
     * @return void
     */
    public static function registerClassMethod($method_id)
    {
        ClassLikeChecker::registerClass(explode('::', $method_id)[0]);
    }

    /**
     * @param  string $method_id
     * @return MethodStorage|null
     */
    public static function getStorage($method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        ClassLikeChecker::registerClass($fq_class_name);

        $class_storage = ClassLikeChecker::$storage[$fq_class_name];

        if (isset($class_storage->methods[strtolower($method_name)])) {
            return $class_storage->methods[strtolower($method_name)];
        }

        return null;
    }

    /**
     * @param  string       $method_id
     * @param  CodeLocation $code_location
     * @param  array        $suppressed_issues
     * @return false|null
     */
    public static function checkMethodNotDeprecated($method_id, CodeLocation $code_location, array $suppressed_issues)
    {
        $method_id = (string) self::getDeclaringMethodId($method_id);
        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        if ($storage->deprecated) {
            if (IssueBuffer::accepts(
                new DeprecatedMethod(
                    'The method ' . MethodChecker::getCasedMethodId($method_id) . ' has been marked as deprecated',
                    $code_location
                ),
                $suppressed_issues
            )) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param  string           $method_id
     * @param  string|null      $calling_context
     * @param  StatementsSource $source
     * @param  CodeLocation     $code_location
     * @param  array            $suppressed_issues
     * @return false|null
     */
    public static function checkMethodVisibility(
        $method_id,
        $calling_context,
        StatementsSource $source,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        self::registerClassMethod($method_id);

        $declared_method_id = self::getDeclaringMethodId($method_id);

        $method_class = explode('::', (string)$method_id)[0];
        $declaring_method_class = explode('::', (string)$declared_method_id)[0];
        $method_name = explode('::', $method_id)[1];

        if (TraitChecker::traitExists($declaring_method_class) && ClassLikeChecker::classUsesTrait($method_class, $declaring_method_class)) {
            return null;
        }

        // if the calling class is the same, we know the method exists, so it must be visible
        if ($method_class === $calling_context) {
            return null;
        }

        if ($source->getSource() instanceof TraitChecker && $declaring_method_class === $source->getFQCLN()) {
            return null;
        }

        $storage = self::getStorage((string)$declared_method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        switch ($storage->visibility) {
            case self::VISIBILITY_PUBLIC:
                return null;

            case self::VISIBILITY_PRIVATE:
                if (!$calling_context || $declaring_method_class !== $calling_context) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access private method ' . MethodChecker::getCasedMethodId($method_id) .
                                ' from context ' . $calling_context,
                            $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                }

                return null;

            case self::VISIBILITY_PROTECTED:
                if ($declaring_method_class === $calling_context) {
                    return null;
                }

                if (!$calling_context) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access protected method ' . $method_id,
                            $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }

                    return null;
                }

                if (ClassChecker::classExtends($declaring_method_class, $calling_context) &&
                    MethodChecker::methodExists($calling_context . '::' . $method_name)
                ) {
                    return null;
                }

                if (!ClassChecker::classExtends($calling_context, $declaring_method_class)) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access protected method ' . MethodChecker::getCasedMethodId($method_id) .
                                ' from context ' . $calling_context,
                            $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                }
        }

        return null;
    }

    /**
     * @param string $method_id
     * @param string $declaring_method_id
     * @return void
     */
    public static function setDeclaringMethodId($method_id, $declaring_method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        ClassLikeChecker::$storage[$fq_class_name]->declaring_method_ids[$method_name] = $declaring_method_id;
    }

    /**
     * @param  string $method_id
     * @return string|null
     */
    public static function getDeclaringMethodId($method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        if (isset(ClassLikeChecker::$storage[$fq_class_name]->declaring_method_ids[$method_name])) {
            return ClassLikeChecker::$storage[$fq_class_name]->declaring_method_ids[$method_name];
        }
    }

    /**
     * @param string  $method_id
     * @param string  $overridden_method_id
     * @return void
     */
    public static function setOverriddenMethodId($method_id, $overridden_method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        ClassLikeChecker::$storage[$fq_class_name]->overridden_method_ids[$method_name][] = $overridden_method_id;
    }

    /**
     * @param  string $method_id
     * @return array<string>
     */
    public static function getOverriddenMethodIds($method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        $class_storage = ClassLikeChecker::$storage[$fq_class_name];

        if (isset($class_storage->overridden_method_ids[$method_name])) {
            return $class_storage->overridden_method_ids[$method_name];
        }

        return [];
    }

    /**
     * @param  string $original_method_id
     * @return string
     */
    public static function getCasedMethodId($original_method_id)
    {
        $method_id = self::getDeclaringMethodId($original_method_id);

        if ($method_id === null) {
            throw new \UnexpectedValueException('Cannot get declaring method id for ' . $original_method_id);
        }

        $storage = self::getStorage($method_id);

        if (!$storage) {
            throw new \UnexpectedValueException('$storage should not be null');
        }

        list($fq_class_name) = explode('::', $method_id);

        return $fq_class_name . '::' .$storage->cased_name;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {

    }
}
