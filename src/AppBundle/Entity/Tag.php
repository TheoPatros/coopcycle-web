<?php

namespace AppBundle\Entity;

use Symfony\Component\Validator\Constraints as Assert;

class Tag
{
    protected $id;

    protected $name;

    private $slug;

    /**
    * @Assert\NotBlank()
    */
    private $color;

    private $createdAt;

    private $updatedAt;

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug()
    {
        return $this->slug;
    }

    public function getColor()
    {
        return $this->color;
    }

    public function setColor($color)
    {
        $this->color = $color;

        return $this;
    }
}
