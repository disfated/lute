<?php

namespace App\Entity;

use App\DTO\TermDTO;
use App\Repository\TermRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'words')]
class Term
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'WoID', type: Types::SMALLINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: 'Language', inversedBy: 'terms', fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'WoLgID', referencedColumnName: 'LgID', nullable: false)]
    private ?Language $language = null;

    #[ORM\Column(name: 'WoText', length: 250)]
    private ?string $WoText = null;

    #[ORM\Column(name: 'WoTextLC', length: 250)]
    private ?string $WoTextLC = null;

    #[ORM\Column(name: 'WoStatus', type: Types::SMALLINT)]
    private ?int $WoStatus = 1;

    #[ORM\Column(name: 'WoTranslation', length: 500, nullable: true)]
    private ?string $WoTranslation = null;

    #[ORM\Column(name: 'WoRomanization', length: 100, nullable: true)]
    private ?string $WoRomanization = null;

    #[ORM\Column(name: 'WoTokenCount', type: Types::SMALLINT)]
    private ?int $WoTokenCount = null;

    #[ORM\JoinTable(name: 'wordtags')]
    #[ORM\JoinColumn(name: 'WtWoID', referencedColumnName: 'WoID')]
    #[ORM\InverseJoinColumn(name: 'WtTgID', referencedColumnName: 'TgID')]
    #[ORM\ManyToMany(targetEntity: TermTag::class, inversedBy:'Terms', cascade: ['persist'], fetch: 'EAGER')]
    private Collection $termTags;

    #[ORM\JoinTable(name: 'wordparents')]
    #[ORM\JoinColumn(name: 'WpWoID', referencedColumnName: 'WoID')]
    #[ORM\InverseJoinColumn(name: 'WpParentWoID', referencedColumnName: 'WoID')]
    #[ORM\ManyToMany(targetEntity: Term::class, inversedBy:'children', cascade: ['persist'], fetch: 'EAGER')]
    private Collection $parents;
    /* Really, a word can have only one parent, but since we have a
       join table, I'll treat it like a many-to-many join in the
       private members, but the interface will only have setParent()
       and getParent(). */

    #[ORM\JoinTable(name: 'wordparents')]
    #[ORM\JoinColumn(name: 'WpParentWoID', referencedColumnName: 'WoID')]
    #[ORM\InverseJoinColumn(name: 'WpWoID', referencedColumnName: 'WoID')]
    #[ORM\ManyToMany(targetEntity: Term::class, mappedBy:'parents', cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    private Collection $children;

    #[ORM\OneToMany(targetEntity: 'TermImage', mappedBy: 'term', fetch: 'EAGER', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'WiWoID', referencedColumnName: 'WoID', nullable: false)]
    private Collection $images;
    /* Currently, a word can only have one image. */

    #[ORM\OneToOne(targetEntity: 'TermFlashMessage', mappedBy: 'Term', fetch: 'EAGER', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private ?TermFlashMessage $termFlashMessage = null;


    public function __construct(?Language $lang = null, ?string $text = null)
    {
        $this->termTags = new ArrayCollection();
        $this->parents = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->images = new ArrayCollection();

        if ($lang != null)
            $this->setLanguage($lang);
        if ($text != null)
            $this->setText($text);
    }

    public function getID(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(Language $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function setText(string $WoText): self
    {
        if ($this->language == null)
            throw new \Exception("Must do Term->setLanguage() before setText()");

        // Clean up encoding cruft.
        $t = trim($WoText);
        $zws = mb_chr(0x200B); // zero-width space.
        $t = str_replace($zws, '', $t);
        $nbsp = mb_chr(0x00A0); // non-breaking space
        $t = str_replace($nbsp, ' ', $t);

        $tokens = $this->getLanguage()->getParsedTokens($t);

        // Terms can't contain paragraph markers.
        $isNotPara = function($tok) {
            return $tok->token !== "¶";
        };
        $tokens = array_filter($tokens, $isNotPara);
        $tokstrings = array_map(fn($tok) => $tok->token, $tokens);

        $t = implode($zws, $tokstrings);

        $text_changed = $this->WoText != null && $this->WoText != $t;
        if ($this->id != null && $text_changed) {
            $msg = "Cannot change text of term '{$this->WoText}' (id = {$this->id}) once saved.";
            throw new \Exception($msg);
        }

        $this->WoText = $t;
        $this->WoTextLC = mb_strtolower($t);

        $this->calcTokenCount();
        return $this;
    }

    private function calcTokenCount() {
        $tc = 0;
        $zws = mb_chr(0x200B); // zero-width space.
        if ($this->WoText != null) {
            $parts = explode($zws, $this->WoText);
            $tc = count($parts);
        }
        $this->setTokenCount($tc);
    }

    public function getText(): ?string
    {
        return $this->WoText;
    }

    public function getTextLC(): ?string
    {
        return $this->WoTextLC;
    }

    public function setStatus(?int $n): self
    {
        $this->WoStatus = $n;
        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->WoStatus;
    }

    public function setTokenCount(?int $n): self
    {
        $this->WoTokenCount = $n;
        return $this;
    }

    public function getTokenCount(): ?int
    {
        return $this->WoTokenCount;
    }

    public function setTranslation(?string $WoTranslation): self
    {
        $this->WoTranslation = $WoTranslation;
        return $this;
    }

    public function getTranslation(): ?string
    {
        return $this->WoTranslation;
    }

    public function setRomanization(?string $WoRomanization): self
    {
        $this->WoRomanization = $WoRomanization;
        return $this;
    }

    public function getRomanization(): ?string
    {
        return $this->WoRomanization;
    }

    /**
     * @return Collection<int, TextTag>
     */
    public function getTermTags(): Collection
    {
        return $this->termTags;
    }

    public function removeAllTermTags(): void {
        foreach ($this->termTags as $tt) {
            $this->removeTermTag($tt);
        }
    }

    public function addTermTag(TermTag $termTag): self
    {
        if (!$this->termTags->contains($termTag)) {
            $this->termTags->add($termTag);
        }
        return $this;
    }

    public function removeTermTag(TermTag $termTag): self
    {
        $this->termTags->removeElement($termTag);
        return $this;
    }

    /**
     * @return Term or null
     */
    public function getParent(): ?Term
    {
        if ($this->parents->isEmpty()) {
            return null;
        }

        // The last element in the array is the current active parent.
        $p = $this->parents->last();
        if ($p == false) {
            return null;
        }
        else {
            return $p;
        }
    }

    public function setParent(?Term $parent): self
    {
        $p = $this->getParent();
        if ($p != null) {
            $this->parents->removeElement($p);
            $p->getChildren()->removeElement($this);
        }
        if ($parent != null) {
            /**
             * @psalm-suppress InvalidArgument
             */
            $this->parents->add($parent);
            $parent->children[] = $this;
        }
        return $this;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getCurrentImage(bool $strip_jpeg = true): ?string
    {
        if (count($this->images) == 0) {
            return null;
        }
        $i = $this->images->getValues()[0];

        $src = $i->getSource();

        if (! $strip_jpeg)
            return $src;

        // Ugly hack: we have to remove the .jpeg at the end, because
        // Symfony doesn't handle params with periods.
        // Ref https://github.com/symfony/symfony/issues/25541.
        // The src/ImageController adds the .jpeg at the end again to
        // find the actual file.
        return preg_replace('/\.jpeg$/ui', '', $src);
    }

    public function setCurrentImage(?string $s): self
    {
        if (! $this->images->isEmpty()) {
            $this->images->remove(0);
        }
        if ($s != null) {
            $ti = new TermImage();
            $ti->setTerm($this);
            $ti->setSource($s);
            /**
             * @psalm-suppress InvalidArgument
             */
            $this->images->add($ti);
        }
        return $this;
    }

    public function createTermDTO(): TermDTO
    {
        $f = new TermDTO();
        $f->id = $this->getID();
        $f->language = $this->getLanguage();
        $f->Text = $this->getText();
        $f->Status = $this->getStatus();
        $f->Translation = $this->getTranslation();
        $f->Romanization = $this->getRomanization();
        $f->TokenCount = $this->getTokenCount();
        $f->CurrentImage = $this->getCurrentImage();
        $f->FlashMessage = $this->getFlashMessage();

        $p = $this->getParent();
        if ($p != null) {
            $f->ParentID = $p->getID();
            $f->ParentText = $p->getText();
        }


        if (($f->Romanization ?? '') == '') {
            $f->Romanization = $f->language->getParser()->getReading($f->Text);
        }

        foreach ($this->getTermTags() as $tt) {
            $f->termTags[] = $tt->getText();
        }

        return $f;
    }

    public function getFlashMessage(): ?string
    {
        if ($this->termFlashMessage == null)
            return null;
        return $this->termFlashMessage->getMessage();
    }

    public function setFlashMessage(string $m): self
    {
        $tfm = $this->termFlashMessage;
        if ($tfm == null) {
            $tfm = new TermFlashMessage();
            $this->termFlashMessage = $tfm;
            $tfm->setTerm($this);
        }
        $tfm->setMessage($m);
        return $this;
    }

    public function popFlashMessage(): ?string
    {
        if ($this->termFlashMessage == null)
            return null;
        $m = $this->termFlashMessage->getMessage();
        $this->termFlashMessage = null;
        return $m;
    }

}
