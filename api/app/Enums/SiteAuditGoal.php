<?php

declare(strict_types=1);

namespace App\Enums;

/** The tasks a simulated visitor can try on a site. Custom goals are stored with the `custom` key and their own wording. */
enum SiteAuditGoal: string
{
    case Understand = 'understand';
    case Pricing = 'pricing';
    case SignUp = 'sign_up';
    case Contact = 'contact';
    case Buy = 'buy';
    case Docs = 'docs';
    case Custom = 'custom';

    /**
     * Get the goal's short name, shown when picking journeys.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Understand => __('Understand the product'),
            self::Pricing => __('Find the price'),
            self::SignUp => __('Sign up'),
            self::Contact => __('Get in touch'),
            self::Buy => __('Buy something'),
            self::Docs => __('Find the documentation'),
            self::Custom => __('Your own task'),
        };
    }

    /**
     * Get the instruction the simulated visitor is given, written as a person would put it to themselves.
     *
     * @return string
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Understand => 'You have just heard of this company. Work out what it offers and who it is for, then stop.',
            self::Pricing => 'You are considering this product. Find out how much it costs for a small team.',
            self::SignUp => 'You want to try the product. Get as far as the sign-up or trial form and fill it in up to, but not including, submitting it.',
            self::Contact => 'You have a question before buying. Find a way to contact a person at the company.',
            self::Buy => 'You want to buy a typical product. Get to the checkout, stopping before entering payment details.',
            self::Docs => 'You are a developer evaluating this product. Find its documentation and a getting-started guide.',
            self::Custom => '',
        };
    }

    /**
     * Get the goals a new audit starts with.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Understand, self::Pricing, self::SignUp];
    }
}
