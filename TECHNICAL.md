# Labdoo Technical Documentation

This document is a domain and module overview. Read [AGENTS.md](AGENTS.md) for contributor instructions and verify operational details against the active branch. Module summaries describe responsibilities rather than a guaranteed inventory of implemented features.

## Project Overview

Labdoo is a collaborative Drupal platform that helps manage the collection, refurbishment, and distribution of electronic devices (dootronics) to educational projects worldwide. The platform coordinates volunteers, hubs, and educational villages to facilitate the donation process and track the lifecycle of devices.

The target Drupal version is pinned in `composer.lock`. Compare it with the installed runtime before testing; an unaligned checkout does not establish compatibility with the locked target.

## Main Entities

### Dootronics
Electronic devices (laptops, tablets, etc.) that are donated, refurbished, and distributed to educational projects. Dootronics have various states throughout their lifecycle and contain information about their specifications, condition, and location.

### Dootrips
Volunteer trips that help transport dootronics from one location to another, eliminating shipping costs and reducing carbon footprint. Dootrips connect donors with educational projects across geographical boundaries.

### Hubs
Local centers where volunteers collect, refurbish, and prepare dootronics for distribution. Hubs serve as coordination points in the Labdoo network.

### Edoovillages
Educational projects (schools, orphanages, etc.) that receive dootronics. These entities contain information about their location, needs, and the devices they have received.

### Teams
Groups of volunteers working together on specific aspects of the Labdoo project. Teams can be organized around geographical areas, functions, or specific initiatives.

### Users
Platform users with different roles and permissions, including administrators, hub managers, team members, and regular users.

## Custom Modules

### Core Entity Modules

#### labdoo_dootronics
Manages the lifecycle of electronic devices from donation to deployment. Includes functionality for:
- Device registration and inventory management
- Technical specifications tracking
- Status updates and history
- Assignment to edoovillages

#### labdoo_dootrip
Handles the coordination of volunteer trips for transporting devices. Features include:
- Trip registration and planning
- Route visualization on maps
- Device pickup and delivery coordination
- Trip status tracking

#### labdoo_edoovillage
Manages educational projects that receive devices. Functionality includes:
- Project registration and profile management
- Needs assessment and device requests
- Impact tracking and reporting
- Communication with donors and volunteers

#### labdoo_hub
Coordinates local centers for device collection and refurbishment. Features include:
- Hub registration and management
- Inventory tracking
- Volunteer coordination
- Activity reporting

#### labdoo_team
Manages volunteer teams and their activities. Includes:
- Team creation and membership management
- Task assignment and tracking
- Team communication tools
- Activity reporting

### Supporting Modules

#### labdoo_common
Provides shared functionality used across multiple modules, including:
- Utility functions
- Shared services
- Common interfaces
- Base classes for exports and other operations

#### labdoo_notifications
Handles email notifications and communication within the platform:
- Email templates
- Notification triggers
- Contact forms
- Subscription management

#### labdoo_migrate
Tools for migrating data from previous versions of the platform:
- Content synchronization
- User migration
- Permission mapping
- Entity relationship preservation

#### labdoo_statistics
Generates reports and statistics about platform activities:
- Device tracking metrics
- Impact assessment
- User activity reports
- Geographic distribution analysis

#### labdoo_gallery
Manages media content related to projects and activities:
- Photo galleries
- Media organization
- Display options
- Media embedding

#### labdoo_global_action
Coordinates global initiatives and campaigns:
- Action generation for different entity types
- Campaign management
- Global event coordination

#### labdoo_admin_toolbar
Customizes the administrative interface for Labdoo-specific needs:
- Custom menu items
- Quick access to common tasks
- Role-based toolbar customization

#### labdoo_privileges
Manages custom permissions and access control:
- Role-based permissions
- Content access rules
- Operation restrictions

#### labdoo_search_fix
Provides fixes and enhancements for the search functionality:
- Search index optimization
- Custom search filters
- Search result improvements

#### labdoo_user
Extends user functionality with Labdoo-specific features:
- User profiles
- Activity tracking
- Contribution history
- Reputation system

#### labdoo_story
Manages success stories and testimonials:
- Story creation and editing
- Media integration
- Categorization and tagging
- Display options

#### labdoo_wiki
Provides wiki-like documentation functionality:
- Collaborative content creation
- Version history
- Categorization
- Search integration

#### mini_wiki
A lightweight wiki implementation for specific documentation needs:
- Simple page creation
- Basic formatting
- Categorization
- Search integration

#### github_issues
Integrates with GitHub for issue tracking:
- Issue creation from the platform
- Status synchronization
- Comment integration

#### queue_manager
Manages background processing tasks:
- Task queuing
- Scheduled execution
- Failure handling
- Performance optimization

#### simple_slideshow
Provides slideshow functionality for media presentation:
- Image slideshows
- Configuration options
- Display integration

## Drush commands and operational scope

Inspect command annotations in the affected module's `src/Commands` directory before choosing a command. The old command examples in this document were illustrative and did not consistently match registered commands. For example, the current source declares `labdoo-synchronize-basic-pages` and `labdoo-search:fix-index`; verify names and options on the active branch.

Consult the [agent workflow](docs/agent-workflow.md) before running operations. Bootstrapping Drush, saving an entity or processing a queue can touch a live database or external services. Use an identified isolated environment for tests. Migration and repair guidance lives in `.agents/skills/migrations/SKILL.md`; deployment guidance lives in `.agents/skills/operations/SKILL.md`.

## Database Structure

The Labdoo platform uses Drupal's entity system to store data. The main content types are implemented as custom entities with fields for specific attributes. The database follows Drupal's standard schema with additional tables for custom entities and fields.

## API integration

Inspect the active branch's routing, enabled modules and access requirements before relying on an endpoint. This overview does not define a supported external API contract.

## Multilingual Support

Labdoo supports multiple languages through Drupal's translation system. Content can be translated into various languages, and the user interface adapts to the user's preferred language.

## Caching Strategy

The platform uses Redis for caching to improve performance. Cache bins are configured for different types of data, and the cache is rebuilt automatically when content changes.

## Search Functionality

Search is implemented using Search API with custom indexes for different entity types. The search functionality allows users to find devices, projects, trips, and other content based on various criteria.