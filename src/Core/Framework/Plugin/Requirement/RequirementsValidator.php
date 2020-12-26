<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Plugin\Requirement;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Plugin\Composer\Factory;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\Requirement\Exception\ConflictingPackageException;
use Shopware\Core\Framework\Plugin\Requirement\Exception\MissingRequirementException;
use Shopware\Core\Framework\Plugin\Requirement\Exception\RequirementStackException;
use Shopware\Core\Framework\Plugin\Requirement\Exception\VersionMismatchException;

class RequirementsValidator
{
    /**
     * @var EntityRepositoryInterface
     */
    private $pluginRepo;

    /**
     * @var string
     */
    private $projectDir;

    /**
     * @var Composer
     */
    private $pluginComposer;

    public function __construct(EntityRepositoryInterface $pluginRepo, string $projectDir)
    {
        $this->pluginRepo = $pluginRepo;
        $this->projectDir = $projectDir;
    }

    /**
     * @throws RequirementStackException
     */
    public function validateRequirements(PluginEntity $plugin, Context $context, string $method): void
    {
        $exceptionStack = new RequirementExceptionStack();

        $pluginDependencies = $this->getPluginDependencies($plugin);

        $pluginDependencies = $this->validateComposerPackages($pluginDependencies, $exceptionStack);
        $pluginDependencies = $this->validateInstalledPlugins($context, $plugin, $pluginDependencies, $exceptionStack);
        $pluginDependencies = $this->validateShippedDependencies($plugin, $pluginDependencies, $exceptionStack);

        $this->addRemainingRequirementsAsException($pluginDependencies['require'], $exceptionStack);

        $exceptionStack->tryToThrow($method);
    }

    /**
     * resolveActiveDependants returns all active dependants of the given plugin.
     *
     * @param PluginEntity[] $dependants the plugins to check for a dependency on the given plugin
     *
     * @return PluginEntity[]
     */
    public function resolveActiveDependants(PluginEntity $dependency, array $dependants): array
    {
        return array_filter($dependants, function ($dependant) use ($dependency) {
            if (!$dependant->getActive()) {
                return false;
            }

            return $this->dependsOn($dependant, $dependency);
        });
    }

    /**
     * dependsOn determines, whether a given plugin depends on another one.
     *
     * @param PluginEntity $plugin     the plugin to be checked
     * @param PluginEntity $dependency the potential dependency
     */
    private function dependsOn(PluginEntity $plugin, PluginEntity $dependency): bool
    {
        foreach (array_keys($this->getPluginDependencies($plugin)['require']) as $requirement) {
            if ($requirement === $dependency->getComposerName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{'require': Link[], 'conflict': Link[]}
     */
    private function getPluginDependencies(PluginEntity $plugin): array
    {
        $this->pluginComposer = $this->getComposer($this->projectDir . '/' . $plugin->getPath());
        $package = $this->pluginComposer->getPackage();

        return [
            'require' => $package->getRequires(),
            'conflict' => $package->getConflicts(),
        ];
    }

    /**
     * @param array{'require': Link[], 'conflict': Link[]} $pluginDependencies
     *
     * @return array{'require': Link[], 'conflict': Link[]}
     */
    private function validateComposerPackages(
        array $pluginDependencies,
        RequirementExceptionStack $exceptionStack
    ): array {
        $shopwareProjectComposer = $this->getComposer($this->projectDir);

        return $this->checkComposerDependencies(
            $pluginDependencies,
            $exceptionStack,
            $shopwareProjectComposer
        );
    }

    private function getComposer(string $composerPath): Composer
    {
        return Factory::createComposer($composerPath);
    }

    /**
     * @param array{'require': Link[], 'conflict': Link[]} $pluginDependencies
     *
     * @return array{'require': Link[], 'conflict': Link[]}
     */
    private function checkComposerDependencies(
        array $pluginDependencies,
        RequirementExceptionStack $exceptionStack,
        Composer $pluginComposer
    ): array {
        $packages = $pluginComposer->getRepositoryManager()->getLocalRepository()->getPackages();

        // Get PHP extension "packages"
        $packages = array_merge($packages, (new PlatformRepository())->getPackages());

        foreach ($packages as $package) {
            $pluginDependencies['require'] = $this->checkRequirement(
                $pluginDependencies['require'],
                $package->getName(),
                new Constraint('==', $package->getVersion()),
                $exceptionStack
            );

            $pluginDependencies['conflict'] = $this->checkConflict(
                $pluginDependencies['conflict'],
                $this->pluginComposer->getPackage()->getName(),
                $package->getName(),
                new Constraint('==', $package->getVersion()),
                $exceptionStack
            );

            foreach ($package->getReplaces() as $replace) {
                $replaceConstraint = $replace->getConstraint();

                if ($replace->getPrettyConstraint() === 'self.version') {
                    $replaceConstraint = new Constraint('==', $package->getVersion());
                }

                $pluginDependencies['require'] = $this->checkRequirement(
                    $pluginDependencies['require'],
                    $replace->getTarget(),
                    $replaceConstraint,
                    $exceptionStack
                );

                $pluginDependencies['conflict'] = $this->checkConflict(
                    $pluginDependencies['conflict'],
                    $this->pluginComposer->getPackage()->getName(),
                    $replace->getTarget(),
                    $replaceConstraint,
                    $exceptionStack
                );
            }
        }

        return $pluginDependencies;
    }

    /**
     * @param array{'require': Link[], 'conflict': Link[]} $pluginDependencies
     *
     * @return array{'require': Link[], 'conflict': Link[]}
     */
    private function validateInstalledPlugins(
        Context $context,
        PluginEntity $installingPlugin,
        array $pluginDependencies,
        RequirementExceptionStack $exceptionStack
    ): array {
        $parser = new VersionParser();

        foreach ($this->getInstalledPlugins($context) as $pluginEntity) {
            $installedPluginComposer = $this->getComposer($this->projectDir . '/' . $pluginEntity->getPath());
            $installedPluginConflicts = $installedPluginComposer->getPackage()->getConflicts();

            $pluginDependencies['require'] = $this->checkRequirement(
                $pluginDependencies['require'],
                $pluginEntity->getComposerName(),
                new Constraint('==', $parser->normalize($pluginEntity->getVersion())),
                $exceptionStack
            );

            // Reverse check, if the already installed plugins do conflict with the current
            $this->checkConflict(
                $installedPluginConflicts,
                $installedPluginComposer->getPackage()->getName(),
                $this->pluginComposer->getPackage()->getName(),
                new Constraint('==', $parser->normalize($installingPlugin->getVersion())),
                $exceptionStack
            );

            $pluginDependencies['conflict'] = $this->checkConflict(
                $pluginDependencies['conflict'],
                $this->pluginComposer->getPackage()->getName(),
                $pluginEntity->getComposerName(),
                new Constraint('==', $parser->normalize($pluginEntity->getVersion())),
                $exceptionStack
            );
        }

        return $pluginDependencies;
    }

    private function getInstalledPlugins(Context $context): PluginCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('installedAt', null)]));
        $criteria->addFilter(new EqualsFilter('active', true));
        /** @var PluginCollection $plugins */
        $plugins = $this->pluginRepo->search($criteria, $context)->getEntities();

        return $plugins;
    }

    /**
     * @param Link[] $pluginRequirements
     *
     * @return Link[]
     */
    private function checkRequirement(
        array $pluginRequirements,
        string $installedName,
        ?ConstraintInterface $installedVersion,
        RequirementExceptionStack $exceptionStack
    ): array {
        if (!isset($pluginRequirements[$installedName])) {
            return $pluginRequirements;
        }

        $constraint = $pluginRequirements[$installedName]->getConstraint();

        if ($constraint === null || $installedVersion === null) {
            return $pluginRequirements;
        }

        if ($constraint->matches($installedVersion) === false) {
            $exceptionStack->add(
                new VersionMismatchException($installedName, $constraint->getPrettyString(), $installedVersion->getPrettyString())
            );
        }

        unset($pluginRequirements[$installedName]);

        return $pluginRequirements;
    }

    /**
     * @param Link[] $pluginConflicts
     *
     * @return Link[]
     */
    private function checkConflict(
        array $pluginConflicts,
        string $sourceName,
        string $targetName,
        ?ConstraintInterface $installedVersion,
        RequirementExceptionStack $exceptionStack
    ): array {
        if (!isset($pluginConflicts[$targetName])) {
            return $pluginConflicts;
        }

        $constraint = $pluginConflicts[$targetName]->getConstraint();

        if ($constraint === null || $installedVersion === null) {
            return $pluginConflicts;
        }

        if ($constraint->matches($installedVersion) === true) {
            $exceptionStack->add(
                new ConflictingPackageException($sourceName, $targetName, $installedVersion->getPrettyString())
            );
        }

        unset($pluginConflicts[$targetName]);

        return $pluginConflicts;
    }

    /**
     * @param Link[] $pluginRequirements
     */
    private function addRemainingRequirementsAsException(
        array $pluginRequirements,
        RequirementExceptionStack $exceptionStack
    ): void {
        foreach ($pluginRequirements as $installedPackage => $requirement) {
            $exceptionStack->add(
                new MissingRequirementException($installedPackage, $requirement->getPrettyConstraint())
            );
        }
    }

    /**
     * @param array{'require': Link[], 'conflict': Link[]} $pluginDependencies
     *
     * @return array{'require': Link[], 'conflict': Link[]}
     */
    private function validateShippedDependencies(
        PluginEntity $plugin,
        array $pluginDependencies,
        RequirementExceptionStack $exceptionStack
    ): array {
        if ($plugin->getManagedByComposer()) {
            return $pluginDependencies;
        }

        $vendorDir = $this->pluginComposer->getConfig()->get('vendor-dir');
        if (!is_dir($vendorDir)) {
            return $pluginDependencies;
        }
        $pluginDependencies = $this->checkComposerDependencies(
            $pluginDependencies,
            $exceptionStack,
            $this->pluginComposer
        );

        return $pluginDependencies;
    }
}
