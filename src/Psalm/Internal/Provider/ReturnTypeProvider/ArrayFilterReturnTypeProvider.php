<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider\ReturnTypeProvider;

use Override;
use PhpParser;
use Psalm\CodeLocation;
use Psalm\Exception\ComplicatedExpressionException;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\FormulaGenerator;
use Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\AssertionReconciler;
use Psalm\Issue\InvalidReturnType;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;

use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function is_string;
use function mt_rand;
use function reset;
use function spl_object_id;

/**
 * @internal
 */
final class ArrayFilterReturnTypeProvider implements FunctionReturnTypeProviderInterface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function getFunctionIds(): array
    {
        return ['array_filter'];
    }

    #[Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Union
    {
        $statements_source = $event->getStatementsSource();
        $call_args = $event->getCallArgs();
        $context = $event->getContext();
        $code_location = $event->getCodeLocation();
        if (!$statements_source instanceof StatementsAnalyzer
            || !$call_args
        ) {
            return Type::getMixed();
        }

        $fallback = Type::getArrayAtomic();
        $array_arg = $call_args[0]->value ?? null;
        if (!$array_arg) {
            $first_arg_array = $fallback;
        } else {
            $first_arg_type = $statements_source->node_data->getType($array_arg);
            if (!$first_arg_type || $first_arg_type->isMixed()) {
                $first_arg_array = $fallback;
            } else {
                $first_arg_array = $first_arg_type->hasType('array')
                                   && ($array_atomic_type = $first_arg_type->getArray())
                                   && ($array_atomic_type instanceof TArray
                                       || $array_atomic_type instanceof TKeyedArray)
                    ? $array_atomic_type
                    : $fallback;
            }
        }

        if ($first_arg_array instanceof TArray) {
            $inner_type = $first_arg_array->type_params[1];
            $key_type = $first_arg_array->type_params[0];
        } else {
            $inner_type = $first_arg_array->getGenericValueType();
            $key_type = $first_arg_array->getGenericKeyType();

            if (!isset($call_args[1]) && $first_arg_array->fallback_params === null) {
                $had_one = count($first_arg_array->properties) === 1;

                $new_properties = array_filter(
                    array_map(
                        static function ($keyed_type) use ($statements_source, $context) {
                            $prev_keyed_type = $keyed_type;

                            $keyed_type = AssertionReconciler::reconcile(
                                new Truthy(),
                                $keyed_type,
                                '',
                                $statements_source,
                                $context->inside_loop,
                                [],
                                null,
                                $statements_source->getSuppressedIssues(),
                            );

                            return $keyed_type->setPossiblyUndefined(!$prev_keyed_type->isAlwaysTruthy());
                        },
                        $first_arg_array->properties,
                    ),
                    static fn($keyed_type) => !$keyed_type->isNever(),
                );

                if (!$new_properties) {
                    return Type::getEmptyArray();
                }

                return new Union([TKeyedArray::make(
                    $new_properties,
                    null,
                    $first_arg_array->fallback_params,
                    $first_arg_array->is_list && $had_one,
                )]);
            }
        }

        if (!isset($call_args[1])) {
            $inner_type = AssertionReconciler::reconcile(
                new Truthy(),
                $inner_type,
                '',
                $statements_source,
                $context->inside_loop,
                [],
                null,
                $statements_source->getSuppressedIssues(),
            );

            if ($first_arg_array instanceof TKeyedArray
                && $first_arg_array->is_list
                && $key_type->isSingleIntLiteral()
                && $key_type->getSingleIntLiteral()->value === 0
            ) {
                return Type::getList(
                    $inner_type,
                );
            }

            if ($key_type->getLiteralStrings()) {
                $key_type = $key_type->getBuilder()->addType(new TString)->freeze();
            }

            if ($key_type->getLiteralInts()) {
                $key_type = $key_type->getBuilder()->addType(new TInt)->freeze();
            }

            if ($inner_type->isUnionEmpty()) {
                return Type::getEmptyArray();
            }

            return new Union([
                new TArray([
                    $key_type,
                    $inner_type,
                ]),
            ]);
        }

        if (!isset($call_args[2])) {
            $function_call_arg = $call_args[1];

            $callable_extended_var_id = ExpressionIdentifier::getExtendedVarId(
                $function_call_arg->value,
                null,
                $statements_source,
            );

            $mapping_function_ids = [];
            if ($callable_extended_var_id) {
                $possibly_function_ids = $context->vars_in_scope[$callable_extended_var_id] ?? null;
                // @todo for array callables
                if ($possibly_function_ids && $possibly_function_ids->allStringLiterals()) {
                    foreach ($possibly_function_ids->getLiteralStrings() as $atomic) {
                        $mapping_function_ids[] = $atomic->value;
                    }
                }
            }

            if ($function_call_arg->value instanceof PhpParser\Node\Scalar\String_
                || $function_call_arg->value instanceof PhpParser\Node\Expr\Array_
                || $function_call_arg->value instanceof PhpParser\Node\Expr\BinaryOp\Concat
                || $mapping_function_ids !== []
            ) {
                if ($mapping_function_ids === []) {
                    $mapping_function_ids = CallAnalyzer::getFunctionIdsFromCallableArg(
                        $statements_source,
                        $function_call_arg->value,
                    );
                }

                if ($array_arg && $mapping_function_ids) {
                    $assertions = [];

                    $fake_var_discriminator = mt_rand();
                    ArrayMapReturnTypeProvider::getReturnTypeFromMappingIds(
                        $statements_source,
                        $mapping_function_ids,
                        $context,
                        $function_call_arg,
                        array_slice($call_args, 0, 1),
                        $assertions,
                        $fake_var_discriminator,
                    );

                    $extended_var_id = ExpressionIdentifier::getExtendedVarId(
                        $array_arg,
                        null,
                        $statements_source,
                    );

                    $assertion_id = $extended_var_id . "[\$__fake_{$fake_var_discriminator}_offset_var__]";

                    if (isset($assertions[$assertion_id])) {
                        $changed_var_ids = [];

                        $assertions = [
                            '$inner_type' => $assertions[$assertion_id],
                        ];

                        [$reconciled_types, $_] = Reconciler::reconcileKeyedTypes(
                            $assertions,
                            $assertions,
                            ['$inner_type' => $inner_type],
                            [],
                            $changed_var_ids,
                            ['$inner_type' => true],
                            $statements_source,
                            $statements_source->getTemplateTypeMap() ?: [],
                            false,
                            new CodeLocation($statements_source, $function_call_arg->value),
                        );

                        if (isset($reconciled_types['$inner_type'])) {
                            $inner_type = $reconciled_types['$inner_type'];
                        }
                    }

                    ArrayMapReturnTypeProvider::cleanContext($context, $fake_var_discriminator);
                }
            } elseif (($function_call_arg->value instanceof PhpParser\Node\Expr\Closure
                    || $function_call_arg->value instanceof PhpParser\Node\Expr\ArrowFunction)
                && ($second_arg_type = $statements_source->node_data->getType($function_call_arg->value))
                && ($closure_types = $second_arg_type->getClosureTypes())
            ) {
                $closure_atomic_type = reset($closure_types);
                $closure_return_type = $closure_atomic_type->return_type ?: Type::getMixed();

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::maybeAdd(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_filter',
                            $code_location,
                        ),
                        $statements_source->getSuppressedIssues(),
                    );

                    return Type::getArray();
                }

                /** @var list<PhpParser\Node\Stmt> */
                $function_call_stmts = $function_call_arg->value->getStmts();

                if (count($function_call_stmts) === 1 && count($function_call_arg->value->params)) {
                    $first_param = $function_call_arg->value->params[0];
                    $stmt = $function_call_stmts[0];

                    if ($first_param->variadic === false
                        && $first_param->var instanceof PhpParser\Node\Expr\Variable
                        && is_string($first_param->var->name)
                        && $stmt instanceof PhpParser\Node\Stmt\Return_
                        && $stmt->expr
                    ) {
                        $codebase = $statements_source->getCodebase();

                        $cond_object_id = spl_object_id($stmt->expr);

                        try {
                            $filter_clauses = FormulaGenerator::getFormula(
                                $cond_object_id,
                                $cond_object_id,
                                $stmt->expr,
                                $context->self,
                                $statements_source,
                                $codebase,
                            );
                        } catch (ComplicatedExpressionException) {
                            $filter_clauses = [];
                        }

                        $assertions = Algebra::getTruthsFromFormula(
                            $filter_clauses,
                            $cond_object_id,
                        );

                        if (isset($assertions['$' . $first_param->var->name])) {
                            $changed_var_ids = [];

                            $assertions = ['$inner_type' => $assertions['$' . $first_param->var->name]];

                            [$reconciled_types, $_] = Reconciler::reconcileKeyedTypes(
                                $assertions,
                                $assertions,
                                ['$inner_type' => $inner_type],
                                [],
                                $changed_var_ids,
                                ['$inner_type' => true],
                                $statements_source,
                                $statements_source->getTemplateTypeMap() ?: [],
                                false,
                                new CodeLocation($statements_source, $stmt),
                            );

                            if (isset($reconciled_types['$inner_type'])) {
                                $inner_type = $reconciled_types['$inner_type'];
                            }
                        }
                    }
                }
            }

            return new Union([
                new TArray([
                    $key_type,
                    $inner_type,
                ]),
            ]);
        }

        if ($inner_type->isUnionEmpty()) {
            return Type::getEmptyArray();
        }

        return new Union([
            new TArray([
                $key_type,
                $inner_type,
            ]),
        ]);
    }
}
