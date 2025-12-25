<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Filter;
use App\Models\FilterGroup;
use Illuminate\Database\Seeder;

class FilterSystemSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'company' => FilterGroup::firstOrCreate(
                ['name' => 'Company'],
                [
                    'description' => 'Company related filters',
                    'sort_order' => 1,
                ]
            ),
            'role' => FilterGroup::firstOrCreate(
                ['name' => 'Role'],
                [
                    'description' => 'Role and position related filters',
                    'sort_order' => 2,
                ]
            ),
            'demographhics' => FilterGroup::firstOrCreate(
                ['name' => 'Demographics'],
                [
                    'description' => 'Location and demographic filters',
                    'sort_order' => 3,
                ]
            ),
            'personal' => FilterGroup::firstOrCreate(
                ['name' => 'Personal'],
                [
                    'description' => 'Personal information filters',
                    'sort_order' => 3,
                ]
            ),
        ];

        $this->createCompanyGroupFilters($groups['company']);
        $this->createRoleGroupFilters($groups['role']);
        $this->createDemographicsGroupFilters($groups['demographhics']);
        $this->createPersonalGroupFilters($groups['personal']);

        // Only call FilterValuesSeeder if explicitly needed
        // Comment out to avoid duplicate entry errors
        // $this->call(FilterValuesSeeder::class);

    }

    private function createCompanyGroupFilters(FilterGroup $group): void
    {
        // Company Name (Company)
        Filter::updateOrCreate(
            ['filter_id' => 'company_name_company'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company Name',
                'elasticsearch_field' => 'company',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'text',
                    'search_fields' => ['company', 'company_also_known_as', 'company.keyword'],
                ],
                'sort_order' => 1,
            ]
        );

        // Company Name (Contact)
        Filter::updateOrCreate(
            ['filter_id' => 'company_name_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company Name',
                'elasticsearch_field' => 'company',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'text',
                    'search_fields' => ['company', 'company_also_known_as'],
                ],
                'sort_order' => 1,
            ]
        );

        // Industry
        Filter::updateOrCreate(
            ['filter_id' => 'industry'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Industry',
                'elasticsearch_field' => 'industry',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['industry'],
                ],
                'sort_order' => 3,
            ]
        );

        // Company Size / Employee (Company)
        Filter::updateOrCreate(
            ['filter_id' => 'company_headcount'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company Size / Employee',
                'elasticsearch_field' => 'employees',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'sort_order' => 4,
            ]
        );

        // Company Size / Employee (Contact)
        Filter::updateOrCreate(
            ['filter_id' => 'company_headcount_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company Size / Employee',
                'elasticsearch_field' => 'employees',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'sort_order' => 4,
            ]
        );

        // Company Domain (Company)
        Filter::updateOrCreate(
            ['filter_id' => 'company_domain_company'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company Domain',
                'elasticsearch_field' => 'website',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['website'],
                ],
                'sort_order' => 2,
            ]
        );

        // Company Domain (Contact)
        Filter::updateOrCreate(
            ['filter_id' => 'company_domain_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company Domain',
                'elasticsearch_field' => 'website',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['website'],
                ],
                'sort_order' => 2,
            ]
        );

        // Technologies (Company)
        Filter::updateOrCreate(
            ['filter_id' => 'technologies'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Technologies',
                'elasticsearch_field' => 'technologies',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['technologies', 'company_technologies'],
                ],
                'sort_order' => 5,
            ]
        );

        // Technologies (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'company_technologies_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Technologies',
                'elasticsearch_field' => 'company_technologies',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['company_technologies', 'technologies'],
                    'join_via_company' => true,
                ],
                'sort_order' => 5,
            ]
        );

        // Annual Revenue
        Filter::updateOrCreate(
            ['filter_id' => 'annual_revenue'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Annual Revenue',
                'elasticsearch_field' => 'annual_revenue',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'sort_order' => 6,
            ]
        );

        // Founded Year
        Filter::updateOrCreate(
            ['filter_id' => 'founded_year'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Founded Year',
                'elasticsearch_field' => 'founded_year',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'sort_order' => 7,
            ]
        );

        // Total Funding
        Filter::updateOrCreate(
            ['filter_id' => 'total_funding'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Total Funding',
                'elasticsearch_field' => 'total_funding',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'sort_order' => 8,
            ]
        );

        // Annual Revenue (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'annual_revenue_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Annual Revenue',
                'elasticsearch_field' => 'annual_revenue',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'settings' => [
                    'join_via_company' => true,
                ],
                'sort_order' => 6,
            ]
        );

        // Founded Year (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'founded_year_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Founded Year',
                'elasticsearch_field' => 'founded_year',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'settings' => [
                    'join_via_company' => true,
                ],
                'sort_order' => 7,
            ]
        );

        // Total Funding (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'total_funding_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Total Funding',
                'elasticsearch_field' => 'total_funding',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'settings' => [
                    'join_via_company' => true,
                ],
                'sort_order' => 8,
            ]
        );

        // Company Size / Employee (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'employee_count_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company Size / Employee',
                'elasticsearch_field' => 'employee_count',
                'value_source' => 'predefined',
                'value_type' => 'number',
                'input_type' => 'select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'settings' => [
                    'join_via_company' => true,
                ],
                'sort_order' => 9,
            ]
        );
    }

    private function createRoleGroupFilters(FilterGroup $group): void
    {
        // Job Title
        Filter::updateOrCreate(
            ['filter_id' => 'job_title'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Job Title',
                'elasticsearch_field' => 'title',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'text',
                    'search_fields' => ['title', 'job_title', 'normalized_title', 'title_keywords'],
                ],
                'sort_order' => 1,
            ]
        );

        // Function/Department
        Filter::updateOrCreate(
            ['filter_id' => 'departments'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Department',
                'elasticsearch_field' => 'departments',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['departments'],
                ],
                'sort_order' => 2,
            ]
        );

        // Seniority Level
        Filter::updateOrCreate(
            ['filter_id' => 'seniority'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Seniority Level',
                'elasticsearch_field' => 'seniority_level',
                'value_source' => 'predefined',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => false,
                'allows_exclusion' => true,
                'settings' => [
                    'field_type' => 'keyword',
                ],
                'sort_order' => 3,
            ]
        );

        // TODO: Future, No elastic field with this data
        // Filter::create([
        //     'filter_group_id' => $group->id,
        //     'filter_id' => 'years_of_experience',
        //     'filter_type' => 'contact',
        //     'name' => 'Years of Experience',
        //     'elasticsearch_field' => 'experience_years',
        //     'value_source' => 'predefined',
        //     'value_type' => 'number',
        //     'input_type' => 'select',
        //     'is_searchable' => false,
        //     'allows_exclusion' => true,
        //     'settings' => [
        //         'target_model' => Contact::class,
        //         'use_range' => true
        //     ],
        //     'sort_order' => 4
        // ]);
    }

    private function createDemographicsGroupFilters(FilterGroup $group): void
    {
        // Company Country
        Filter::updateOrCreate(
            ['filter_id' => 'company_country'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company Country',
                'elasticsearch_field' => 'location.country',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.country'],
                ],
                'sort_order' => 1,
            ]
        );

        // Company State
        Filter::updateOrCreate(
            ['filter_id' => 'company_state'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company State',
                'elasticsearch_field' => 'location.state',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.state'],
                ],
                'sort_order' => 2,
            ]
        );

        // Company City
        Filter::updateOrCreate(
            ['filter_id' => 'company_city'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'company',
                'name' => 'Company City',
                'elasticsearch_field' => 'location.city',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Company::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.city'],
                ],
                'sort_order' => 3,
            ]
        );

        // Contact Country
        Filter::updateOrCreate(
            ['filter_id' => 'contact_country'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Contact Country',
                'elasticsearch_field' => 'location.country',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.country'],
                ],
                'sort_order' => 4,
            ]
        );

        // Contact State
        Filter::updateOrCreate(
            ['filter_id' => 'contact_state'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Contact State',
                'elasticsearch_field' => 'location.state',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.state'],
                ],
                'sort_order' => 5,
            ]
        );

        // Contact City
        Filter::updateOrCreate(
            ['filter_id' => 'contact_city'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Contact City',
                'elasticsearch_field' => 'location.city',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.city'],
                ],
                'sort_order' => 6,
            ]
        );

        // Company Country (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'company_country_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company Country',
                'elasticsearch_field' => 'location.country',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.country'],
                    'join_via_company' => true,
                ],
                'sort_order' => 7,
            ]
        );

        // Company State (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'company_state_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company State',
                'elasticsearch_field' => 'location.state',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.state'],
                    'join_via_company' => true,
                ],
                'sort_order' => 8,
            ]
        );

        // Company City (Contact - filters via company join)
        Filter::updateOrCreate(
            ['filter_id' => 'company_city_contact'],
            [
                'is_active' => true,
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Company City',
                'elasticsearch_field' => 'location.city',
                'value_source' => 'elasticsearch',
                'value_type' => 'string',
                'input_type' => 'multi_select',
                'is_searchable' => true,
                'allows_exclusion' => true,
                'settings' => [
                    'target_model' => Contact::class,
                    'field_type' => 'keyword',
                    'search_fields' => ['location.city'],
                    'join_via_company' => true,
                ],
                'sort_order' => 9,
            ]
        );
    }

    private function createPersonalGroupFilters(FilterGroup $group): void
    {
        // First Name
        Filter::updateOrCreate(
            ['filter_id' => 'first_name'],
            [
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'First Name',
                'elasticsearch_field' => 'first_name',
                'value_source' => 'direct',
                'value_type' => 'string',
                'input_type' => 'text',
                'is_searchable' => false,
                'allows_exclusion' => false,
                'settings' => [
                    'validation' => [
                        'min_length' => 2,
                        'max_length' => 50,
                        'pattern' => '/^[a-zA-Z\s\'-]+$/',
                    ],
                ],
                'sort_order' => 1,
            ]
        );

        // Last Name
        Filter::updateOrCreate(
            ['filter_id' => 'last_name'],
            [
                'filter_group_id' => $group->id,
                'filter_type' => 'contact',
                'name' => 'Last Name',
                'elasticsearch_field' => 'last_name',
                'value_source' => 'direct',
                'value_type' => 'string',
                'input_type' => 'text',
                'is_searchable' => false,
                'allows_exclusion' => false,
                'settings' => [
                    'validation' => [
                        'min_length' => 2,
                        'max_length' => 50,
                        'pattern' => '/^[a-zA-Z\s\'-]+$/',
                    ],
                ],
                'sort_order' => 2,
            ]
        );
    }
}
