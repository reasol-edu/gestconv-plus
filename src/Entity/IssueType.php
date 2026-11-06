<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Tipo de incidencia: un nodo de una lista jerárquica configurable en cada centro. Los responsables de un nodo son los
 * que tiene asignados él y todos sus ascendentes.
 */
#[ORM\Entity(repositoryClass: IssueTypeRepository::class)]
#[ORM\Table(name: 'issue_type')]
class IssueType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?IssueType $parent = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'issue_type_manager')]
    private Collection $managers;

    public function __construct()
    {
        $this->managers = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function setEducationalCentre(EducationalCentre $educationalCentre): static
    {
        $this->educationalCentre = $educationalCentre;

        return $this;
    }

    public function getParent(): ?IssueType
    {
        return $this->parent;
    }

    public function setParent(?IssueType $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, Teacher> los responsables asignados a este nodo (sin los de sus ascendentes) */
    public function getManagers(): Collection
    {
        return $this->managers;
    }

    public function addManager(Teacher $teacher): static
    {
        if (!$this->managers->contains($teacher)) {
            $this->managers->add($teacher);
        }

        return $this;
    }

    public function removeManager(Teacher $teacher): static
    {
        $this->managers->removeElement($teacher);

        return $this;
    }

    /** @return list<IssueType> los ascendentes, del padre hacia la raíz */
    public function getAncestors(): array
    {
        $ancestors = [];
        $seen      = [$this->id->toRfc4122() => true];
        for ($node = $this->parent; $node !== null && !isset($seen[$node->getId()->toRfc4122()]); $node = $node->getParent()) {
            $ancestors[] = $node;
            $seen[$node->getId()->toRfc4122()] = true;
        }

        return $ancestors;
    }

    /**
     * Los responsables del nodo: los asignados a él y a todos sus ascendentes, sin repetir.
     *
     * @return list<Teacher>
     */
    public function getEffectiveManagers(): array
    {
        $effective = [];
        foreach ([$this, ...$this->getAncestors()] as $node) {
            foreach ($node->getManagers() as $manager) {
                $effective[$manager->getId()->toRfc4122()] = $manager;
            }
        }

        return array_values($effective);
    }

    /** El nombre con la ruta completa desde la raíz: «Mantenimiento › Electricidad». */
    public function getPath(string $separator = ' › '): string
    {
        return implode($separator, array_reverse(array_map(static fn (IssueType $t): string => $t->getName(), [$this, ...$this->getAncestors()])));
    }

    /** Un nodo no puede colgar de sí mismo ni de uno de sus descendientes. */
    public function wouldCycleUnder(?IssueType $newParent): bool
    {
        if ($newParent === null) {
            return false;
        }
        if ($newParent->getId()->equals($this->id)) {
            return true;
        }
        foreach ($newParent->getAncestors() as $ancestor) {
            if ($ancestor->getId()->equals($this->id)) {
                return true;
            }
        }

        return false;
    }
}
