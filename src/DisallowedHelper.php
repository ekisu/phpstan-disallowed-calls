<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ConstantScalarType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;
use Spaze\PHPStan\Rules\Disallowed\Params\DisallowedCallParam;

class DisallowedHelper
{

	/** @var IsAllowedFileHelper */
	private $isAllowedFileHelper;


	public function __construct(IsAllowedFileHelper $isAllowedFileHelper)
	{
		$this->isAllowedFileHelper = $isAllowedFileHelper;
	}


	private function isAllowed(Scope $scope, ?CallLike $node, DisallowedCall $disallowedCall): bool
	{
		foreach ($disallowedCall->getAllowInCalls() as $call) {
			if ($scope->getFunction() instanceof MethodReflection) {
				$name = $this->getFullyQualified($scope->getFunction()->getDeclaringClass()->getDisplayName(false), $scope->getFunction());
			} elseif ($scope->getFunction() instanceof FunctionReflection) {
				$name = $scope->getFunction()->getName();
			} else {
				$name = '';
			}
			if (fnmatch($call, $name, FNM_NOESCAPE | FNM_CASEFOLD)) {
				return $this->hasAllowedParamsInAllowed($scope, $node, $disallowedCall);
			}
		}
		foreach ($disallowedCall->getAllowIn() as $allowedPath) {
			$file = $scope->getTraitReflection() ? $scope->getTraitReflection()->getFileName() : $scope->getFile();
			if ($file !== null && fnmatch($this->isAllowedFileHelper->absolutizePath($allowedPath), $file)) {
				return $this->hasAllowedParamsInAllowed($scope, $node, $disallowedCall);
			}
		}
		if ($disallowedCall->getAllowExceptParams()) {
			return $this->hasAllowedParams($scope, $node, $disallowedCall->getAllowExceptParams(), false);
		}
		if ($disallowedCall->getAllowParamsAnywhere()) {
			return $this->hasAllowedParams($scope, $node, $disallowedCall->getAllowParamsAnywhere(), true);
		}
		return false;
	}


	private function hasAllowedParamsInAllowed(Scope $scope, ?CallLike $node, DisallowedCall $disallowedCall): bool
	{
		if ($disallowedCall->getAllowExceptParamsInAllowed()) {
			return $this->hasAllowedParams($scope, $node, $disallowedCall->getAllowExceptParamsInAllowed(), false);
		}
		if ($disallowedCall->getAllowParamsInAllowed()) {
			return $this->hasAllowedParams($scope, $node, $disallowedCall->getAllowParamsInAllowed(), true);
		}
		return true;
	}


	/**
	 * @param Scope $scope
	 * @param CallLike|null $node
	 * @param array<int, DisallowedCallParam> $allowConfig
	 * @param bool $paramsRequired
	 * @return bool
	 */
	private function hasAllowedParams(Scope $scope, ?CallLike $node, array $allowConfig, bool $paramsRequired): bool
	{
		if (!$node) {
			return true;
		}

		foreach ($allowConfig as $param => $value) {
			$type = $this->getArgType($node, $scope, $param);
			if (!$type instanceof ConstantScalarType) {
				return !$paramsRequired;
			}
			if (!$value->matches($type)) {
				return false;
			}
		}
		return true;
	}


	private function getArgType(CallLike $node, Scope $scope, int $param): ?Type
	{
		$arg = $node->getArgs()[$param - 1] ?? null;
		return $arg ? $scope->getType($arg->value) : null;
	}


	/**
	 * @param CallLike|null $node
	 * @param Scope $scope
	 * @param string $name
	 * @param string|null $displayName
	 * @param DisallowedCall[] $disallowedCalls
	 * @param string|null $message
	 * @return RuleError[]
	 */
	public function getDisallowedMessage(?CallLike $node, Scope $scope, string $name, ?string $displayName, array $disallowedCalls, ?string $message = null): array
	{
		foreach ($disallowedCalls as $disallowedCall) {
			if ($this->callMatches($disallowedCall, $name) && !$this->isAllowed($scope, $node, $disallowedCall)) {
				return [
					RuleErrorBuilder::message(sprintf(
						$message ?? 'Calling %s is forbidden, %s%s',
						($displayName && $displayName !== $name) ? "{$name}() (as {$displayName}())" : "{$name}()",
						$disallowedCall->getMessage(),
						$disallowedCall->getCall() !== $name ? " [{$name}() matches {$disallowedCall->getCall()}()]" : ''
					))
						->identifier($disallowedCall->getErrorIdentifier())
						->build(),
				];
			}
		}
		return [];
	}


	private function callMatches(DisallowedCall $disallowedCall, string $name): bool
	{
		if ($name === $disallowedCall->getCall() || fnmatch($disallowedCall->getCall(), $name, FNM_NOESCAPE | FNM_CASEFOLD)) {
			return true;
		}
		return false;
	}


	/**
	 * @param Name|Expr $class
	 * @param CallLike $node
	 * @param Scope $scope
	 * @param DisallowedCall[] $disallowedCalls
	 * @return RuleError[]
	 * @throws ClassNotFoundException
	 */
	public function getDisallowedMethodMessage($class, CallLike $node, Scope $scope, array $disallowedCalls): array
	{
		if (!isset($node->name) || !($node->name instanceof Identifier)) {
			return [];
		}

		$calledOnType = $this->resolveType($class, $scope);
		if ($calledOnType->canCallMethods()->yes() && $calledOnType->hasMethod($node->name->name)->yes()) {
			$method = $calledOnType->getMethod($node->name->name, $scope);
			$calledAs = ($calledOnType instanceof TypeWithClassName ? $this->getFullyQualified($calledOnType->getClassName(), $method) : null);

			foreach ($method->getDeclaringClass()->getTraits() as $trait) {
				if ($trait->hasMethod($method->getName())) {
					$declaredAs = $this->getFullyQualified($trait->getDisplayName(), $method);
					$message = $this->getDisallowedMessage($node, $scope, $declaredAs, $calledAs, $disallowedCalls);
					if ($message) {
						return $message;
					}
				}
			}
		} else {
			return [];
		}

		$declaredAs = $this->getFullyQualified($method->getDeclaringClass()->getDisplayName(false), $method);
		return $this->getDisallowedMessage($node, $scope, $declaredAs, $calledAs, $disallowedCalls);
	}


	private function getFullyQualified(string $class, MethodReflection $method): string
	{
		return sprintf('%s::%s', $class, $method->getName());
	}


	/**
	 * @param Name|Expr $class
	 * @param Scope $scope
	 * @return Type
	 */
	public function resolveType($class, Scope $scope): Type
	{
		return $class instanceof Name ? new ObjectType($scope->resolveName($class)) : $scope->getType($class);
	}


	/**
	 * @param Scope $scope
	 * @param DisallowedConstant $disallowedConstant
	 * @return bool
	 */
	private function isAllowedPath(Scope $scope, DisallowedConstant $disallowedConstant): bool
	{
		foreach ($disallowedConstant->getAllowIn() as $allowedPath) {
			if (fnmatch($this->isAllowedFileHelper->absolutizePath($allowedPath), $scope->getFile())) {
				return true;
			}
		}
		return false;
	}


	/**
	 * @param string $constant
	 * @param Scope $scope
	 * @param string|null $displayName
	 * @param DisallowedConstant[] $disallowedConstants
	 * @return RuleError[]
	 */
	public function getDisallowedConstantMessage(string $constant, Scope $scope, ?string $displayName, array $disallowedConstants): array
	{
		foreach ($disallowedConstants as $disallowedConstant) {
			if ($disallowedConstant->getConstant() === $constant && !$this->isAllowedPath($scope, $disallowedConstant)) {
				return [
					RuleErrorBuilder::message(sprintf(
						'Using %s%s is forbidden, %s',
						$disallowedConstant->getConstant(),
						$displayName && $displayName !== $disallowedConstant->getConstant() ? ' (as ' . $displayName . ')' : '',
						$disallowedConstant->getMessage()
					))
						->identifier($disallowedConstant->getErrorIdentifier())
						->build(),
				];
			}
		}
		return [];
	}

}
